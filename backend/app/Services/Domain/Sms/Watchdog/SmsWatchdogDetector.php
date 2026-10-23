<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms\Watchdog;

use HiEvents\DomainObjects\Enums\SmsAutoReplyType;
use HiEvents\DomainObjects\Enums\SmsMessageDirection;
use HiEvents\DomainObjects\Enums\SmsWatchdogAnomalyType;
use HiEvents\DomainObjects\Generated\OrganizerSmsSettingDomainObjectAbstract;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\OrganizerSmsSettingDomainObject;
use HiEvents\DomainObjects\SmsConversationDomainObject;
use HiEvents\DomainObjects\SmsMessageDomainObject;
use HiEvents\DomainObjects\SmsRegistrationConfirmationDomainObject;
use HiEvents\DomainObjects\Status\SmsMessageStatus;
use HiEvents\Helper\SmsAcknowledgementDetector;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerSmsSettingRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsConversationRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsMessageRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsRegistrationConfirmationRepositoryInterface;
use HiEvents\Services\Domain\Sms\DTO\SmsWatchdogAnomalyDTO;
use HiEvents\Services\Domain\Sms\DTO\SmsWatchdogDetectionDTO;
use HiEvents\Services\Domain\Sms\SmsAssistantService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Looks for SMS anomalies of the organizers whose assistant sends replies on its own. Reads only.
 */
class SmsWatchdogDetector
{
    public const WINDOW_HOURS = 24;

    public const REPLY_DELAY_MINUTES = 10;

    public const DUPLICATE_WINDOW_MINUTES = 10;

    public const PENDING_MINUTES = 15;

    public const MAX_ORGANIZERS = 50;

    public const CHECK_LIMIT = 200;

    public const TRAFFIC_LIMIT = 2000;

    private const EXCERPT_LENGTH = 80;

    public function __construct(
        private readonly OrganizerSmsSettingRepositoryInterface $settingsRepository,
        private readonly OrganizerRepositoryInterface $organizerRepository,
        private readonly SmsMessageRepositoryInterface $messageRepository,
        private readonly SmsConversationRepositoryInterface $conversationRepository,
        private readonly SmsRegistrationConfirmationRepositoryInterface $confirmationRepository,
    ) {}

    public function detect(): SmsWatchdogDetectionDTO
    {
        $organizerIds = $this->settingsRepository
            ->findWhere(
                [OrganizerSmsSettingDomainObjectAbstract::ASSISTANT_AUTO_SEND => true],
                [OrganizerSmsSettingDomainObjectAbstract::ID, OrganizerSmsSettingDomainObjectAbstract::ORGANIZER_ID],
            )
            ->map(fn (OrganizerSmsSettingDomainObject $settings) => $settings->getOrganizerId())
            ->unique()
            ->sort()
            ->take(self::MAX_ORGANIZERS)
            ->values();

        if ($organizerIds->isEmpty()) {
            return new SmsWatchdogDetectionDTO;
        }

        $organizers = $this->organizerRepository
            ->findWhereIn('id', $organizerIds->all())
            ->keyBy(fn (OrganizerDomainObject $organizer) => $organizer->getId());

        $now = Carbon::now('UTC');
        $anomalies = [];
        $completeChecks = [];

        foreach ($organizerIds as $organizerId) {
            $name = $organizers->get($organizerId)?->getName() ?? '#'.$organizerId;
            $completeChecks[$organizerId] = [];

            foreach ([
                fn () => $this->unansweredInbound($organizerId, $name, $now),
                fn () => $this->duplicateReplies($organizerId, $name, $now),
                fn () => $this->duplicateRegistrationConfirmations($organizerId, $name, $now),
                fn () => $this->undeliveredOutbound($organizerId, $name, $now),
            ] as $check) {
                [$found, $complete] = $check();
                array_push($anomalies, ...$found);
                array_push($completeChecks[$organizerId], ...$complete);
            }
        }

        return new SmsWatchdogDetectionDTO($anomalies, $completeChecks);
    }

    /**
     * @return array{0: array<int, SmsWatchdogAnomalyDTO>, 1: array<int, string>}
     */
    private function unansweredInbound(int $organizerId, string $organizerName, Carbon $now): array
    {
        $messages = $this->messageRepository->findUnansweredInbound(
            $organizerId,
            $this->since($now),
            $now->copy()->subMinutes(self::REPLY_DELAY_MINUTES)->toDateTimeString(),
            self::CHECK_LIMIT,
        );

        $waiting = $messages
            ->filter(fn (SmsMessageDomainObject $message) => SmsAssistantService::needsReply($message)
                && ! SmsAcknowledgementDetector::isAcknowledgement($message->getContent()))
            ->unique(fn (SmsMessageDomainObject $message) => $message->getSmsConversationId())
            ->values();

        $conversations = $this->conversations($waiting);

        $anomalies = $waiting->map(fn (SmsMessageDomainObject $message) => new SmsWatchdogAnomalyDTO(
            type: SmsWatchdogAnomalyType::UNANSWERED_INBOUND,
            key: 'conversation:'.$message->getSmsConversationId().':inbound:'.$message->getId(),
            organizerId: $organizerId,
            organizerName: $organizerName,
            conversationId: $message->getSmsConversationId(),
            phone: $conversations->get($message->getSmsConversationId())?->getPhone(),
            occurredAt: $message->getCreatedAt(),
            excerpt: $this->excerpt($message->getContent()),
        ))->all();

        return [$anomalies, $this->completeIf($messages->count() < self::CHECK_LIMIT, SmsWatchdogAnomalyType::UNANSWERED_INBOUND)];
    }

    /**
     * Two replies or more to the same inbound SMS (linked to it by the assistant, or sent shortly after it and
     * before the next inbound SMS) when one of them is an automatic answer or they say the same thing.
     * A holding reply followed by the team's answer is expected.
     *
     * @return array{0: array<int, SmsWatchdogAnomalyDTO>, 1: array<int, string>}
     */
    private function duplicateReplies(int $organizerId, string $organizerName, Carbon $now): array
    {
        $traffic = $this->messageRepository->findRepliesTrafficSince($organizerId, $this->since($now), self::TRAFFIC_LIMIT);

        /** @var array<int, array{inbound: SmsMessageDomainObject|null, inboundId: int, conversationId: int, replies: array<int, SmsMessageDomainObject>}> $groups */
        $groups = [];
        $byId = $traffic->keyBy(fn (SmsMessageDomainObject $message) => $message->getId());

        foreach ($traffic->groupBy(fn (SmsMessageDomainObject $message) => $message->getSmsConversationId()) as $conversationId => $thread) {
            $inbound = null;
            foreach ($thread as $message) {
                if ($message->getDirection() === SmsMessageDirection::INBOUND->name) {
                    $inbound = $message;

                    continue;
                }

                if (! $this->isDelivered($message)) {
                    continue;
                }

                $linkedId = $message->getAutoReplyToMessageId();
                if ($linkedId !== null) {
                    $groups[$linkedId] ??= ['inbound' => $byId->get($linkedId), 'inboundId' => $linkedId, 'conversationId' => (int) $conversationId, 'replies' => []];
                    $groups[$linkedId]['replies'][$message->getId()] = $message;
                }

                if ($inbound !== null && $this->minutesBetween($inbound, $message) <= self::DUPLICATE_WINDOW_MINUTES) {
                    $groups[$inbound->getId()] ??= ['inbound' => $inbound, 'inboundId' => $inbound->getId(), 'conversationId' => (int) $conversationId, 'replies' => []];
                    $groups[$inbound->getId()]['replies'][$message->getId()] = $message;
                }
            }
        }

        $duplicates = array_values(array_filter($groups, fn (array $group) => $this->isDuplicate(array_values($group['replies']))));
        $conversations = $this->conversationRepository
            ->findWhereIn('id', array_values(array_unique(array_column($duplicates, 'conversationId'))))
            ->keyBy(fn (SmsConversationDomainObject $conversation) => $conversation->getId());

        $anomalies = array_map(fn (array $group) => new SmsWatchdogAnomalyDTO(
            type: SmsWatchdogAnomalyType::DUPLICATE_REPLY,
            key: 'inbound:'.$group['inboundId'],
            organizerId: $organizerId,
            organizerName: $organizerName,
            conversationId: $group['conversationId'],
            phone: $conversations->get($group['conversationId'])?->getPhone(),
            occurredAt: $group['inbound']?->getCreatedAt() ?? reset($group['replies'])->getCreatedAt(),
            count: count($group['replies']),
            excerpt: $group['inbound'] !== null ? $this->excerpt($group['inbound']->getContent()) : null,
        ), $duplicates);

        return [$anomalies, $this->completeIf($traffic->count() < self::TRAFFIC_LIMIT, SmsWatchdogAnomalyType::DUPLICATE_REPLY)];
    }

    /**
     * @param  array<int, SmsMessageDomainObject>  $replies
     */
    private function isDuplicate(array $replies): bool
    {
        if (count($replies) < 2) {
            return false;
        }

        $automaticAnswers = array_filter($replies, fn (SmsMessageDomainObject $reply) => $reply->getAutoReplyToMessageId() !== null
            && $reply->getAutoReplyType() !== SmsAutoReplyType::HOLDING->name);
        if ($automaticAnswers !== []) {
            return true;
        }

        $contents = array_map(fn (SmsMessageDomainObject $reply) => Str::lower(Str::squish($reply->getContent())), $replies);

        return count(array_unique($contents)) < count($contents);
    }

    /**
     * @return array{0: array<int, SmsWatchdogAnomalyDTO>, 1: array<int, string>}
     */
    private function duplicateRegistrationConfirmations(int $organizerId, string $organizerName, Carbon $now): array
    {
        $confirmations = $this->confirmationRepository->findSentToAlreadyConfirmedPhoneSince($organizerId, $this->since($now), self::CHECK_LIMIT);

        $anomalies = $confirmations->map(fn (SmsRegistrationConfirmationDomainObject $confirmation) => new SmsWatchdogAnomalyDTO(
            type: SmsWatchdogAnomalyType::DUPLICATE_REGISTRATION_CONFIRMATION,
            key: 'confirmation:'.$confirmation->getId(),
            organizerId: $organizerId,
            organizerName: $organizerName,
            phone: $confirmation->getPhone(),
            occurredAt: $confirmation->getCreatedAt(),
            eventId: $confirmation->getEventId(),
        ))->all();

        return [$anomalies, $this->completeIf($confirmations->count() < self::CHECK_LIMIT, SmsWatchdogAnomalyType::DUPLICATE_REGISTRATION_CONFIRMATION)];
    }

    /**
     * @return array{0: array<int, SmsWatchdogAnomalyDTO>, 1: array<int, string>}
     */
    private function undeliveredOutbound(int $organizerId, string $organizerName, Carbon $now): array
    {
        $messages = $this->messageRepository->findUndeliveredOutbound(
            $organizerId,
            $this->since($now),
            $now->copy()->subMinutes(self::PENDING_MINUTES)->toDateTimeString(),
            self::CHECK_LIMIT,
        );

        $conversations = $this->conversations($messages);

        $anomalies = $messages->map(function (SmsMessageDomainObject $message) use ($organizerId, $organizerName, $conversations) {
            $pending = $message->getStatus() === SmsMessageStatus::PENDING->name;

            return new SmsWatchdogAnomalyDTO(
                type: $pending ? SmsWatchdogAnomalyType::STUCK_PENDING : SmsWatchdogAnomalyType::FAILED_OUTBOUND,
                key: 'message:'.$message->getId(),
                organizerId: $organizerId,
                organizerName: $organizerName,
                conversationId: $message->getSmsConversationId(),
                phone: $conversations->get($message->getSmsConversationId())?->getPhone(),
                occurredAt: $message->getCreatedAt(),
                excerpt: $this->excerpt($pending ? $message->getContent() : ($message->getErrorMessage() ?: $message->getContent())),
            );
        })->all();

        $complete = $messages->count() < self::CHECK_LIMIT;

        return [$anomalies, [
            ...$this->completeIf($complete, SmsWatchdogAnomalyType::STUCK_PENDING),
            ...$this->completeIf($complete, SmsWatchdogAnomalyType::FAILED_OUTBOUND),
        ]];
    }

    /**
     * @param  Collection<int, SmsMessageDomainObject>  $messages
     * @return Collection<int, SmsConversationDomainObject>
     */
    private function conversations(Collection $messages): Collection
    {
        $ids = $messages->map(fn (SmsMessageDomainObject $message) => $message->getSmsConversationId())->unique()->values()->all();
        if ($ids === []) {
            return collect();
        }

        return $this->conversationRepository
            ->findWhereIn('id', $ids)
            ->keyBy(fn (SmsConversationDomainObject $conversation) => $conversation->getId());
    }

    private function isDelivered(SmsMessageDomainObject $message): bool
    {
        return ! in_array($message->getStatus(), [SmsMessageStatus::FAILED->name, SmsMessageStatus::EXPIRED->name], true);
    }

    private function minutesBetween(SmsMessageDomainObject $inbound, SmsMessageDomainObject $reply): float
    {
        return Carbon::parse($inbound->getCreatedAt(), 'UTC')->diffInSeconds(Carbon::parse($reply->getCreatedAt(), 'UTC'), false) / 60;
    }

    /**
     * @return array<int, string>
     */
    private function completeIf(bool $complete, SmsWatchdogAnomalyType $type): array
    {
        return $complete ? [$type->name] : [];
    }

    private function since(Carbon $now): string
    {
        return $now->copy()->subHours(self::WINDOW_HOURS)->toDateTimeString();
    }

    private function excerpt(?string $content): ?string
    {
        $content = trim((string) $content);

        return $content === '' ? null : Str::limit(Str::squish($content), self::EXCERPT_LENGTH);
    }
}
