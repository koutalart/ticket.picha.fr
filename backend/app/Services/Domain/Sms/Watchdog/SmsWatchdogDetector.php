<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms\Watchdog;

use HiEvents\DomainObjects\Enums\SmsAutoReplyType;
use HiEvents\DomainObjects\Enums\SmsMessageDirection;
use HiEvents\DomainObjects\Enums\SmsWatchdogAnomalyType;
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
use Illuminate\Config\Repository as Config;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Looks for SMS anomalies of every organizer who can send SMS (own line or shared line). Reads only.
 * Each check is one query for all organizers, bounded by time and by a limit.
 */
class SmsWatchdogDetector
{
    public const WINDOW_HOURS = 24;

    public const DEFAULT_REPLY_DELAY_MINUTES = 10;

    public const DEFAULT_AUTO_SEND_REPLY_DELAY_MINUTES = 5;

    public const DEFAULT_DRAFT_PENDING_MINUTES = 10;

    public const DEFAULT_PENDING_MINUTES = 15;

    public const DUPLICATE_WINDOW_MINUTES = 10;

    public const MAX_ORGANIZERS = 200;

    public const CHECK_LIMIT = 500;

    public const UNANSWERED_LIMIT = 1000;

    public const TRAFFIC_LIMIT = 5000;

    private const EXCERPT_LENGTH = 80;

    /** @var Collection<int, SmsConversationDomainObject> */
    private Collection $conversations;

    /** @var Collection<int, int> ids of the organizers whose assistant sends on its own, as keys */
    private Collection $autoSendOrganizerIds;

    public function __construct(
        private readonly OrganizerSmsSettingRepositoryInterface $settingsRepository,
        private readonly OrganizerRepositoryInterface $organizerRepository,
        private readonly SmsMessageRepositoryInterface $messageRepository,
        private readonly SmsConversationRepositoryInterface $conversationRepository,
        private readonly SmsRegistrationConfirmationRepositoryInterface $confirmationRepository,
        private readonly Config $config,
    ) {
        $this->conversations = collect();
        $this->autoSendOrganizerIds = collect();
    }

    public function replyDelayMinutes(bool $autoSend): int
    {
        return $autoSend
            ? $this->minutes('reply_delay_auto_send_minutes', self::DEFAULT_AUTO_SEND_REPLY_DELAY_MINUTES)
            : $this->minutes('reply_delay_minutes', self::DEFAULT_REPLY_DELAY_MINUTES);
    }

    public function draftPendingMinutes(): int
    {
        return $this->minutes('draft_pending_minutes', self::DEFAULT_DRAFT_PENDING_MINUTES);
    }

    public function pendingMinutes(): int
    {
        return $this->minutes('pending_minutes', self::DEFAULT_PENDING_MINUTES);
    }

    private function minutes(string $key, int $default): int
    {
        $value = $this->config->get('services.sms_watchdog.'.$key);

        return max(1, $value === null || $value === '' ? $default : (int) $value);
    }

    public function detect(): SmsWatchdogDetectionDTO
    {
        $settings = $this->settingsRepository->findWithSmsLine(self::MAX_ORGANIZERS);
        $organizerIds = $settings
            ->map(fn (OrganizerSmsSettingDomainObject $setting) => $setting->getOrganizerId())
            ->unique()
            ->values()
            ->all();

        if ($organizerIds === []) {
            return new SmsWatchdogDetectionDTO;
        }

        $draftOrganizerIds = $settings
            ->filter(fn (OrganizerSmsSettingDomainObject $setting) => $setting->getAssistantEnabled() && ! $setting->getAssistantAutoSend())
            ->map(fn (OrganizerSmsSettingDomainObject $setting) => $setting->getOrganizerId())
            ->unique()
            ->values()
            ->all();

        $names = $this->organizerRepository
            ->findWhereIn('id', $organizerIds)
            ->mapWithKeys(fn (OrganizerDomainObject $organizer) => [$organizer->getId() => $organizer->getName()]);

        $now = Carbon::now('UTC');
        $since = $now->copy()->subHours(self::WINDOW_HOURS)->toDateTimeString();
        $this->conversations = collect();

        $this->autoSendOrganizerIds = $settings
            ->filter(fn (OrganizerSmsSettingDomainObject $setting) => (bool) $setting->getAssistantAutoSend())
            ->map(fn (OrganizerSmsSettingDomainObject $setting) => $setting->getOrganizerId())
            ->flip();

        $unanswered = $this->messageRepository->findUnansweredInbound(
            $organizerIds,
            $since,
            $now->copy()->subMinutes(min($this->replyDelayMinutes(true), $this->replyDelayMinutes(false)))->toDateTimeString(),
            self::UNANSWERED_LIMIT,
        );
        $drafts = $this->conversationRepository->findWaitingAgentDrafts(
            $draftOrganizerIds, $since, $now->copy()->subMinutes($this->draftPendingMinutes())->toDateTimeString(), self::CHECK_LIMIT,
        );
        $traffic = $this->messageRepository->findRepliesTrafficSince($organizerIds, $since, self::TRAFFIC_LIMIT);
        $confirmations = $this->confirmationRepository->findSentToAlreadyConfirmedPhoneSince($organizerIds, $since, self::CHECK_LIMIT);
        $undelivered = $this->messageRepository->findUndeliveredOutbound(
            $organizerIds, $since, $now->copy()->subMinutes($this->pendingMinutes())->toDateTimeString(), self::CHECK_LIMIT,
        );

        $waiting = $this->waitingForReply($unanswered->filter(
            fn (SmsMessageDomainObject $message) => Carbon::parse($message->getCreatedAt(), 'UTC')->lessThanOrEqualTo(
                $now->copy()->subMinutes($this->replyDelayMinutes($this->autoSendOrganizerIds->has($message->getOrganizerId()))),
            ),
        ));
        $duplicateGroups = $this->duplicateGroups($traffic);

        $this->loadConversations([
            ...$waiting->map(fn (SmsMessageDomainObject $message) => $message->getSmsConversationId())->all(),
            ...array_column($duplicateGroups, 'conversationId'),
            ...$undelivered->map(fn (SmsMessageDomainObject $message) => $message->getSmsConversationId())->all(),
        ]);
        foreach ($drafts as $draft) {
            $this->conversations->put($draft->getId(), $draft);
        }

        $name = fn (int $organizerId): string => $names->get($organizerId) ?? '#'.$organizerId;

        $anomalies = [
            ...$this->unansweredAnomalies($waiting, $name),
            ...$this->draftAnomalies($drafts, $name),
            ...$this->duplicateAnomalies($duplicateGroups, $name),
            ...$this->confirmationAnomalies($confirmations, $name),
            ...$this->undeliveredAnomalies($undelivered, $name),
        ];

        $completeChecks = [];
        foreach ($organizerIds as $organizerId) {
            $completeChecks[$organizerId] = array_values(array_filter([
                $unanswered->count() < self::UNANSWERED_LIMIT ? SmsWatchdogAnomalyType::UNANSWERED_INBOUND->name : null,
                $drafts->count() < self::CHECK_LIMIT ? SmsWatchdogAnomalyType::DRAFT_PENDING->name : null,
                $traffic->count() < self::TRAFFIC_LIMIT ? SmsWatchdogAnomalyType::DUPLICATE_REPLY->name : null,
                $confirmations->count() < self::CHECK_LIMIT ? SmsWatchdogAnomalyType::DUPLICATE_REGISTRATION_CONFIRMATION->name : null,
                $undelivered->count() < self::CHECK_LIMIT ? SmsWatchdogAnomalyType::STUCK_PENDING->name : null,
                $undelivered->count() < self::CHECK_LIMIT ? SmsWatchdogAnomalyType::FAILED_OUTBOUND->name : null,
            ]));
        }

        return new SmsWatchdogDetectionDTO($anomalies, $completeChecks);
    }

    /**
     * The first SMS of each conversation that calls for a reply (not an acknowledgement, a reaction or a keyword).
     *
     * @param  Collection<int, SmsMessageDomainObject>  $messages
     * @return Collection<int, SmsMessageDomainObject>
     */
    private function waitingForReply(Collection $messages): Collection
    {
        return $messages
            ->filter(fn (SmsMessageDomainObject $message) => SmsAssistantService::needsReply($message)
                && ! SmsAcknowledgementDetector::isAcknowledgement($message->getContent()))
            ->unique(fn (SmsMessageDomainObject $message) => $message->getSmsConversationId())
            ->values();
    }

    /**
     * @param  Collection<int, SmsMessageDomainObject>  $waiting
     * @return array<int, SmsWatchdogAnomalyDTO>
     */
    private function unansweredAnomalies(Collection $waiting, callable $name): array
    {
        return $waiting->map(fn (SmsMessageDomainObject $message) => new SmsWatchdogAnomalyDTO(
            type: SmsWatchdogAnomalyType::UNANSWERED_INBOUND,
            key: 'conversation:'.$message->getSmsConversationId().':inbound:'.$message->getId(),
            organizerId: $message->getOrganizerId(),
            organizerName: $name($message->getOrganizerId()),
            conversationId: $message->getSmsConversationId(),
            phone: $this->phoneOf($message->getSmsConversationId()),
            occurredAt: $message->getCreatedAt(),
            excerpt: $this->excerpt($message->getContent()),
            thresholdMinutes: $this->replyDelayMinutes($this->autoSendOrganizerIds->has($message->getOrganizerId())),
        ))->all();
    }

    /**
     * @param  Collection<int, SmsConversationDomainObject>  $drafts
     * @return array<int, SmsWatchdogAnomalyDTO>
     */
    private function draftAnomalies(Collection $drafts, callable $name): array
    {
        return $drafts->map(fn (SmsConversationDomainObject $conversation) => new SmsWatchdogAnomalyDTO(
            type: SmsWatchdogAnomalyType::DRAFT_PENDING,
            key: 'conversation:'.$conversation->getId().':draft:'.Carbon::parse($conversation->getDraftGeneratedAt(), 'UTC')->format('YmdHis'),
            organizerId: $conversation->getOrganizerId(),
            organizerName: $name($conversation->getOrganizerId()),
            conversationId: $conversation->getId(),
            phone: $conversation->getPhone(),
            occurredAt: $conversation->getDraftGeneratedAt(),
            excerpt: $this->excerpt($conversation->getDraftReply()),
            thresholdMinutes: $this->draftPendingMinutes(),
        ))->all();
    }

    /**
     * Two replies or more to the same inbound SMS (linked to it by the assistant, or sent shortly after it and
     * before the next inbound SMS) when one of them is an automatic answer or they say the same thing.
     * A holding reply followed by the team's answer is expected.
     *
     * @param  Collection<int, SmsMessageDomainObject>  $traffic
     * @return array<int, array{inbound: SmsMessageDomainObject|null, inboundId: int, organizerId: int, conversationId: int, replies: array<int, SmsMessageDomainObject>}>
     */
    private function duplicateGroups(Collection $traffic): array
    {
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

                $group = fn (int $inboundId, ?SmsMessageDomainObject $source) => $groups[$inboundId] ??= [
                    'inbound' => $source,
                    'inboundId' => $inboundId,
                    'organizerId' => $message->getOrganizerId(),
                    'conversationId' => (int) $conversationId,
                    'replies' => [],
                ];

                $linkedId = $message->getAutoReplyToMessageId();
                if ($linkedId !== null) {
                    $groups[$linkedId] = $group($linkedId, $byId->get($linkedId));
                    $groups[$linkedId]['replies'][$message->getId()] = $message;
                }

                if ($inbound !== null && $this->minutesBetween($inbound, $message) <= self::DUPLICATE_WINDOW_MINUTES) {
                    $groups[$inbound->getId()] = $group($inbound->getId(), $inbound);
                    $groups[$inbound->getId()]['replies'][$message->getId()] = $message;
                }
            }
        }

        return array_values(array_filter($groups, fn (array $group) => $this->isDuplicate(array_values($group['replies']))));
    }

    /**
     * @param  array<int, array{inbound: SmsMessageDomainObject|null, inboundId: int, organizerId: int, conversationId: int, replies: array<int, SmsMessageDomainObject>}>  $groups
     * @return array<int, SmsWatchdogAnomalyDTO>
     */
    private function duplicateAnomalies(array $groups, callable $name): array
    {
        return array_map(function (array $group) use ($name) {
            $firstReply = array_values($group['replies'])[0];

            return new SmsWatchdogAnomalyDTO(
                type: SmsWatchdogAnomalyType::DUPLICATE_REPLY,
                key: 'inbound:'.$group['inboundId'],
                organizerId: $group['organizerId'],
                organizerName: $name($group['organizerId']),
                conversationId: $group['conversationId'],
                phone: $this->phoneOf($group['conversationId']),
                occurredAt: $group['inbound']?->getCreatedAt() ?? $firstReply->getCreatedAt(),
                count: count($group['replies']),
                excerpt: $group['inbound'] !== null ? $this->excerpt($group['inbound']->getContent()) : null,
            );
        }, $groups);
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
     * @param  Collection<int, SmsRegistrationConfirmationDomainObject>  $confirmations
     * @return array<int, SmsWatchdogAnomalyDTO>
     */
    private function confirmationAnomalies(Collection $confirmations, callable $name): array
    {
        return $confirmations->map(fn (SmsRegistrationConfirmationDomainObject $confirmation) => new SmsWatchdogAnomalyDTO(
            type: SmsWatchdogAnomalyType::DUPLICATE_REGISTRATION_CONFIRMATION,
            key: 'confirmation:'.$confirmation->getId(),
            organizerId: $confirmation->getOrganizerId(),
            organizerName: $name($confirmation->getOrganizerId()),
            phone: $confirmation->getPhone(),
            occurredAt: $confirmation->getCreatedAt(),
            eventId: $confirmation->getEventId(),
        ))->all();
    }

    /**
     * @param  Collection<int, SmsMessageDomainObject>  $messages
     * @return array<int, SmsWatchdogAnomalyDTO>
     */
    private function undeliveredAnomalies(Collection $messages, callable $name): array
    {
        return $messages->map(function (SmsMessageDomainObject $message) use ($name) {
            $pending = $message->getStatus() === SmsMessageStatus::PENDING->name;

            return new SmsWatchdogAnomalyDTO(
                type: $pending ? SmsWatchdogAnomalyType::STUCK_PENDING : SmsWatchdogAnomalyType::FAILED_OUTBOUND,
                key: 'message:'.$message->getId(),
                organizerId: $message->getOrganizerId(),
                organizerName: $name($message->getOrganizerId()),
                conversationId: $message->getSmsConversationId(),
                phone: $this->phoneOf($message->getSmsConversationId()),
                occurredAt: $message->getCreatedAt(),
                excerpt: $this->excerpt($pending ? $message->getContent() : ($message->getErrorMessage() ?: $message->getContent())),
                thresholdMinutes: $pending ? $this->pendingMinutes() : null,
            );
        })->all();
    }

    /**
     * @param  array<int, int>  $conversationIds
     */
    private function loadConversations(array $conversationIds): void
    {
        $ids = array_values(array_unique($conversationIds));
        if ($ids === []) {
            return;
        }

        $this->conversations = $this->conversationRepository
            ->findWhereIn('id', $ids)
            ->keyBy(fn (SmsConversationDomainObject $conversation) => $conversation->getId());
    }

    private function phoneOf(int $conversationId): ?string
    {
        return $this->conversations->get($conversationId)?->getPhone();
    }

    private function isDelivered(SmsMessageDomainObject $message): bool
    {
        return ! in_array($message->getStatus(), [SmsMessageStatus::FAILED->name, SmsMessageStatus::EXPIRED->name], true);
    }

    private function minutesBetween(SmsMessageDomainObject $inbound, SmsMessageDomainObject $reply): float
    {
        return Carbon::parse($inbound->getCreatedAt(), 'UTC')->diffInSeconds(Carbon::parse($reply->getCreatedAt(), 'UTC'), false) / 60;
    }

    private function excerpt(?string $content): ?string
    {
        $content = trim((string) $content);

        return $content === '' ? null : Str::limit(Str::squish($content), self::EXCERPT_LENGTH);
    }
}
