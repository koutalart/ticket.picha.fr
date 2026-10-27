<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms\OrganizerAssistant;

use HiEvents\DomainObjects\Enums\OrganizerTeamContactRole;
use HiEvents\DomainObjects\Enums\SmsMessageAuthorType;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\Generated\EventDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\OrganizerDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\OrganizerSmsSettingDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\OrganizerTeamNudgeDomainObjectAbstract;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\OrganizerSmsSettingDomainObject;
use HiEvents\DomainObjects\OrganizerTeamContactDomainObject;
use HiEvents\DomainObjects\OrganizerTeamNudgeDomainObject;
use HiEvents\DomainObjects\Status\OrganizerTeamNudgeStatus;
use HiEvents\Exceptions\InvalidSmsPhoneNumberException;
use HiEvents\Exceptions\OrganizerNudgeException;
use HiEvents\Exceptions\SmsGatewayException;
use HiEvents\Exceptions\SmsGatewayNotConfiguredException;
use HiEvents\Exceptions\SmsRecipientOptedOutException;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerSmsSettingRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerTeamNudgeRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsMessageRepositoryInterface;
use HiEvents\Services\Domain\Event\DailyReport\EventDailyReportService;
use HiEvents\Services\Domain\Sms\DTO\SendSmsDTO;
use HiEvents\Services\Domain\Sms\OrganizerAssistant\DTO\OrganizerNudgePlanDTO;
use HiEvents\Services\Domain\Sms\SmsConversationService;
use HiEvents\Services\Domain\Sms\SmsSendService;
use HiEvents\Services\Domain\Sms\Team\OrganizerTeamContactService;
use HiEvents\Services\Domain\Sms\Team\PlatformSupervisorService;
use Illuminate\Cache\CacheManager;
use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;

/**
 * Nudges a person who runs the organizer (role ORGANIZER) when a message of Anicka that waits for an answer
 * (sms_messages.awaits_reply: the introduction, an assistant answer that asks a question, a previous nudge) got no
 * reply after 72 hours. A nudge waits for an answer too, so the next one comes 72 hours later, until the person
 * writes back. Nudges leave between 9:00 and 19:00 local time only. Contacts in copy, platform supervisors and
 * numbers that replied STOP are never nudged. Reports and activity SMS never wait for an answer.
 *
 * The same sentence is never sent twice to a contact while unused variants remain. Once all were used, the variant
 * whose last use is the oldest comes back.
 */
class OrganizerNudgeService
{
    public const DELAY_HOURS = 72;

    public const FIRST_HOUR = 9;

    public const LAST_HOUR = 19;

    private const LOCK_SECONDS = 120;

    public function __construct(
        private readonly OrganizerSmsSettingRepositoryInterface $settingsRepository,
        private readonly OrganizerTeamNudgeRepositoryInterface $nudgeRepository,
        private readonly SmsMessageRepositoryInterface $messageRepository,
        private readonly OrganizerRepositoryInterface $organizerRepository,
        private readonly EventRepositoryInterface $eventRepository,
        private readonly OrganizerTeamContactService $teamContactService,
        private readonly PlatformSupervisorService $supervisorService,
        private readonly SmsConversationService $conversationService,
        private readonly SmsSendService $sendService,
        private readonly CacheManager $cache,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @return array<int, string>
     */
    public static function variants(): array
    {
        return [
            __('Are you there? Are you getting my messages? 🙂', [], 'fr'),
            __('Just checking that you got my last message. I am here if you need me 🙂', [], 'fr'),
            __('I am getting back to you: did you see my message?', [], 'fr'),
            __('I have not heard back from you. Is everything fine on your side?', [], 'fr'),
            __('A quick sign from you when you have a moment? I am here to help 🙂', [], 'fr'),
            __('In case my message went unnoticed: a short answer is enough!', [], 'fr'),
            __('Did you have time to look at my message? I am listening.', [], 'fr'),
            __('Just tell me if everything is clear on your side 🙂', [], 'fr'),
        ];
    }

    public static function text(int $variant): string
    {
        return self::variants()[$variant]."\n\n".OrganizerAssistantReplyGenerator::SIGNATURE;
    }

    /**
     * @param  array<int, int>  $usedVariants  variants already sent to the contact, oldest first
     */
    public static function chooseVariant(array $usedVariants): int
    {
        $count = count(self::variants());
        for ($variant = 0; $variant < $count; $variant++) {
            if (! in_array($variant, $usedVariants, true)) {
                return $variant;
            }
        }

        $lastUse = [];
        foreach ($usedVariants as $position => $variant) {
            $lastUse[$variant] = $position;
        }
        asort($lastUse);

        return (int) array_key_first($lastUse);
    }

    public static function isWithinHours(Carbon $local): bool
    {
        return $local->hour >= self::FIRST_HOUR && $local->hour < self::LAST_HOUR;
    }

    /**
     * The first moment, from the given one, when a nudge may leave.
     */
    public static function nextSendingTime(Carbon $local): Carbon
    {
        if (self::isWithinHours($local)) {
            return $local->copy();
        }

        $next = $local->copy()->setTime(self::FIRST_HOUR, 0);

        return $local->hour >= self::LAST_HOUR ? $next->addDay() : $next;
    }

    public function findSettings(int $organizerId): ?OrganizerSmsSettingDomainObject
    {
        return $this->settingsRepository->findFirstWhere([OrganizerSmsSettingDomainObjectAbstract::ORGANIZER_ID => $organizerId]);
    }

    /**
     * @throws OrganizerNudgeException
     */
    public function configure(int $organizerId, ?bool $enabled = null, ?int $eventId = null): OrganizerSmsSettingDomainObject
    {
        $settings = $this->findSettings($organizerId);
        if ($settings === null) {
            throw new OrganizerNudgeException(__('This organizer has no SMS settings: connect or share an SMS line first.'));
        }

        $attributes = [];
        if ($enabled !== null) {
            $attributes[OrganizerSmsSettingDomainObjectAbstract::ORGANIZER_NUDGE_ENABLED] = $enabled;
        }

        if ($eventId !== null) {
            /** @var EventDomainObject|null $event */
            $event = $this->eventRepository->findFirstWhere([EventDomainObjectAbstract::ID => $eventId]);
            if ($event === null || $event->getOrganizerId() !== $organizerId) {
                throw new OrganizerNudgeException(__('This event does not belong to the organizer.'));
            }
            $attributes[OrganizerSmsSettingDomainObjectAbstract::ORGANIZER_NUDGE_EVENT_ID] = $eventId;
        }

        return $attributes === [] ? $settings : $this->settingsRepository->updateFromArray($settings->getId(), $attributes);
    }

    public function timezone(int $organizerId, ?OrganizerSmsSettingDomainObject $settings = null): string
    {
        $settings ??= $this->findSettings($organizerId);

        if ($settings?->getOrganizerNudgeEventId() !== null) {
            /** @var EventDomainObject|null $event */
            $event = $this->eventRepository->findFirstWhere([EventDomainObjectAbstract::ID => $settings->getOrganizerNudgeEventId()]);
            if ($event?->getTimezone()) {
                return $event->getTimezone();
            }
        }

        /** @var OrganizerDomainObject|null $organizer */
        $organizer = $this->organizerRepository->findFirstWhere([OrganizerDomainObjectAbstract::ID => $organizerId]);

        return $organizer?->getTimezone() ?: EventDailyReportService::DEFAULT_TIMEZONE;
    }

    /**
     * What would happen now for each ORGANIZER contact of the organizer, without sending anything.
     *
     * @return array<int, OrganizerNudgePlanDTO>
     */
    public function plan(int $organizerId, ?Carbon $now = null): array
    {
        $local = ($now ?? Carbon::now('UTC'))->copy()->setTimezone($this->timezone($organizerId));

        return $this->teamContactService->list($organizerId, OrganizerTeamContactRole::ORGANIZER)
            ->map(fn (OrganizerTeamContactDomainObject $contact) => $this->planFor($organizerId, $contact, $local))
            ->values()
            ->all();
    }

    /**
     * Sends the nudges that are due for the organizers that turned them on.
     *
     * @return array<int, OrganizerNudgePlanDTO> the nudges sent, or that would leave on a dry run
     */
    public function sendDue(?Carbon $now = null, bool $dryRun = false, ?int $onlyOrganizerId = null): array
    {
        $now = ($now ?? Carbon::now('UTC'))->copy()->utc();
        $where = [OrganizerSmsSettingDomainObjectAbstract::ORGANIZER_NUDGE_ENABLED => true];
        if ($onlyOrganizerId !== null) {
            $where[OrganizerSmsSettingDomainObjectAbstract::ORGANIZER_ID] = $onlyOrganizerId;
        }

        $results = [];
        /** @var OrganizerSmsSettingDomainObject $settings */
        foreach ($this->settingsRepository->findWhere($where) as $settings) {
            $organizerId = $settings->getOrganizerId();
            $local = $now->copy()->setTimezone($this->timezone($organizerId, $settings));

            foreach ($this->teamContactService->list($organizerId, OrganizerTeamContactRole::ORGANIZER) as $contact) {
                $plan = $this->planFor($organizerId, $contact, $local);
                if ($plan->state !== OrganizerNudgePlanDTO::STATE_DUE) {
                    continue;
                }

                $results[] = $dryRun ? $plan : $this->send($plan);
            }
        }

        return $results;
    }

    private function planFor(int $organizerId, OrganizerTeamContactDomainObject $contact, Carbon $local): OrganizerNudgePlanDTO
    {
        $base = fn (string $state, array $extra = []) => new OrganizerNudgePlanDTO(...[
            'organizerId' => $organizerId,
            'contactId' => $contact->getId(),
            'phone' => $contact->getPhone(),
            'contactName' => $contact->getName(),
            'state' => $state,
            ...$extra,
        ]);

        if ($this->supervisorService->isSupervisor($contact->getPhone())) {
            return $base(OrganizerNudgePlanDTO::STATE_SUPERVISOR);
        }

        if ($this->conversationService->isOptedOut($organizerId, $contact->getPhone())) {
            return $base(OrganizerNudgePlanDTO::STATE_OPTED_OUT);
        }

        $awaited = $this->messageRepository->findLatestAwaitingReply($organizerId, $contact->getPhone());
        if ($awaited === null) {
            return $base(OrganizerNudgePlanDTO::STATE_NOTHING_AWAITED);
        }

        $awaitedAt = Carbon::parse($awaited->getCreatedAt(), 'UTC')->setTimezone($local->getTimezone());
        $extra = ['awaitedMessageId' => $awaited->getId(), 'awaitedAt' => $awaitedAt->format('Y-m-d H:i')];

        if ($this->messageRepository->hasInboundFromPhoneAfter($organizerId, $contact->getPhone(), $awaited->getId())) {
            return $base(OrganizerNudgePlanDTO::STATE_ANSWERED, $extra);
        }

        $nextAt = self::nextSendingTime(max($awaitedAt->copy()->addHours(self::DELAY_HOURS), $local));
        $variant = self::chooseVariant($this->usedVariants($contact->getId()));
        $extra += ['nextNudgeAt' => $nextAt->format('Y-m-d H:i'), 'variant' => $variant, 'text' => self::text($variant)];

        if ($awaitedAt->copy()->addHours(self::DELAY_HOURS)->gt($local)) {
            return $base(OrganizerNudgePlanDTO::STATE_WAITING, $extra);
        }

        if (! self::isWithinHours($local)) {
            return $base(OrganizerNudgePlanDTO::STATE_OUTSIDE_HOURS, $extra);
        }

        return $base(OrganizerNudgePlanDTO::STATE_DUE, $extra);
    }

    /**
     * @return array<int, int>
     */
    private function usedVariants(int $contactId): array
    {
        return $this->nudgeRepository->findWhere([
            OrganizerTeamNudgeDomainObjectAbstract::ORGANIZER_TEAM_CONTACT_ID => $contactId,
            OrganizerTeamNudgeDomainObjectAbstract::STATUS => OrganizerTeamNudgeStatus::SENT->name,
        ])
            ->sortBy(fn (OrganizerTeamNudgeDomainObject $nudge) => $nudge->getId())
            ->map(fn (OrganizerTeamNudgeDomainObject $nudge) => $nudge->getVariant())
            ->values()
            ->all();
    }

    private function send(OrganizerNudgePlanDTO $plan): OrganizerNudgePlanDTO
    {
        $lock = $this->cache->store()->lock('organizer-nudge:'.$plan->contactId, self::LOCK_SECONDS);
        if (! $lock->get()) {
            return $plan->withState(OrganizerNudgePlanDTO::STATE_ALREADY_CLAIMED);
        }

        try {
            $nudge = $this->nudgeRepository->claim([
                OrganizerTeamNudgeDomainObjectAbstract::ORGANIZER_ID => $plan->organizerId,
                OrganizerTeamNudgeDomainObjectAbstract::ORGANIZER_TEAM_CONTACT_ID => $plan->contactId,
                OrganizerTeamNudgeDomainObjectAbstract::AWAITED_SMS_MESSAGE_ID => $plan->awaitedMessageId,
                OrganizerTeamNudgeDomainObjectAbstract::PHONE => $plan->phone,
                OrganizerTeamNudgeDomainObjectAbstract::VARIANT => $plan->variant,
                OrganizerTeamNudgeDomainObjectAbstract::STATUS => OrganizerTeamNudgeStatus::SENDING->name,
            ]);
            if ($nudge === null) {
                return $plan->withState(OrganizerNudgePlanDTO::STATE_ALREADY_CLAIMED);
            }

            try {
                $message = $this->sendService->send(new SendSmsDTO(
                    organizerId: $plan->organizerId,
                    phone: $plan->phone,
                    content: (string) $plan->text,
                    authorType: SmsMessageAuthorType::AGENT,
                    keepDraft: true,
                    replyInConversation: true,
                    awaitsReply: true,
                ));
            } catch (SmsRecipientOptedOutException|InvalidSmsPhoneNumberException $exception) {
                $this->nudgeRepository->updateFromArray($nudge->getId(), [
                    OrganizerTeamNudgeDomainObjectAbstract::STATUS => OrganizerTeamNudgeStatus::SKIPPED->name,
                ]);

                return $plan->withState($exception instanceof SmsRecipientOptedOutException
                    ? OrganizerNudgePlanDTO::STATE_OPTED_OUT
                    : OrganizerNudgePlanDTO::STATE_FAILED);
            } catch (SmsGatewayNotConfiguredException|SmsGatewayException $exception) {
                $this->nudgeRepository->deleteById($nudge->getId());
                $this->logger->warning('Organizer nudge not sent, it will be tried again', [
                    'organizer_id' => $plan->organizerId,
                    'organizer_team_contact_id' => $plan->contactId,
                    'error' => $exception->getMessage(),
                ]);

                return $plan->withState(OrganizerNudgePlanDTO::STATE_FAILED);
            }

            $this->nudgeRepository->updateFromArray($nudge->getId(), [
                OrganizerTeamNudgeDomainObjectAbstract::STATUS => OrganizerTeamNudgeStatus::SENT->name,
                OrganizerTeamNudgeDomainObjectAbstract::SMS_MESSAGE_ID => $message->getId(),
            ]);

            $this->logger->info('Organizer nudge sent', [
                'organizer_id' => $plan->organizerId,
                'organizer_team_contact_id' => $plan->contactId,
                'variant' => $plan->variant,
            ]);

            return $plan->withState(OrganizerNudgePlanDTO::STATE_SENT);
        } finally {
            $lock->release();
        }
    }
}
