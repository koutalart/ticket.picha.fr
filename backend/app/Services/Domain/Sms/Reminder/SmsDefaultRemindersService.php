<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms\Reminder;

use HiEvents\DomainObjects\Enums\CustomerAddressForm;
use HiEvents\DomainObjects\Enums\SmsCampaignType;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\Generated\OrganizerDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\OrganizerSettingDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\SmsCampaignDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\SmsCampaignRecipientDomainObjectAbstract;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\OrganizerSettingDomainObject;
use HiEvents\DomainObjects\SmsCampaignDomainObject;
use HiEvents\DomainObjects\Status\SmsCampaignRecipientStatus;
use HiEvents\DomainObjects\Status\SmsCampaignStatus;
use HiEvents\Helper\CustomerLocale;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerSettingsRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsCampaignRecipientRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsCampaignRepositoryInterface;
use HiEvents\Services\Domain\Customer\CustomerLocaleResolver;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The 3 reminders created automatically with each new event when the organizer turned them on:
 * one week before at 18:00, the day before at 18:00 and 3 hours before the start, in the event timezone.
 */
class SmsDefaultRemindersService
{
    public const KEY_WEEK_BEFORE = 'J-7';

    public const KEY_DAY_BEFORE = 'J-1';

    public const KEY_SAME_DAY = 'J';

    public const KEYS = [self::KEY_WEEK_BEFORE, self::KEY_DAY_BEFORE, self::KEY_SAME_DAY];

    private const EVENING_HOUR = 18;

    private const SAME_DAY_HOURS_BEFORE = 3;

    public function __construct(
        private readonly OrganizerRepositoryInterface $organizerRepository,
        private readonly OrganizerSettingsRepositoryInterface $organizerSettingsRepository,
        private readonly SmsCampaignRepositoryInterface $campaignRepository,
        private readonly SmsCampaignRecipientRepositoryInterface $recipientRepository,
        private readonly CustomerLocaleResolver $customerLocaleResolver,
        private readonly LoggerInterface $logger,
        private readonly DatabaseManager $databaseManager,
    ) {}

    /**
     * Runs once the event is saved, and never makes the event creation or update fail.
     */
    public function createForEventSafely(EventDomainObject $event): void
    {
        $this->safely('create', $event->getId(), fn () => $this->createForEvent($event));
    }

    public function rescheduleForEventSafely(EventDomainObject $event): void
    {
        $this->safely('reschedule', $event->getId(), fn () => $this->rescheduleForEvent($event));
    }

    public function cancelPendingForEventSafely(int $eventId, bool $automaticOnly = true): void
    {
        $this->safely('cancel', $eventId, fn () => $this->cancelPendingForEvent($eventId, $automaticOnly));
    }

    /**
     * @return SmsCampaignDomainObject[] the reminders created
     */
    public function createForEvent(EventDomainObject $event): array
    {
        if (! $this->isEnabled($event->getOrganizerId())) {
            return [];
        }

        /** @var OrganizerDomainObject|null $organizer */
        $organizer = $this->organizerRepository->findFirstWhere([OrganizerDomainObjectAbstract::ID => $event->getOrganizerId()]);
        if ($organizer === null) {
            return [];
        }

        $addressForm = $this->customerLocaleResolver->addressFormForOrganizerId($organizer->getId());
        $created = [];

        foreach (self::KEYS as $key) {
            $scheduledAt = self::scheduledAt($key, $event);
            if ($scheduledAt === null || ! $this->isInFuture($scheduledAt) || $this->findAutoReminder($event->getId(), $key) !== null) {
                continue;
            }

            try {
                $created[] = $this->campaignRepository->create([
                    SmsCampaignDomainObjectAbstract::ACCOUNT_ID => $organizer->getAccountId(),
                    SmsCampaignDomainObjectAbstract::ORGANIZER_ID => $organizer->getId(),
                    SmsCampaignDomainObjectAbstract::EVENT_ID => $event->getId(),
                    SmsCampaignDomainObjectAbstract::NAME => self::name($key),
                    SmsCampaignDomainObjectAbstract::MESSAGE => self::message($key, $addressForm, $organizer->getName()),
                    SmsCampaignDomainObjectAbstract::TYPE => SmsCampaignType::REMINDER->value,
                    SmsCampaignDomainObjectAbstract::STATUS => SmsCampaignStatus::SCHEDULED->value,
                    SmsCampaignDomainObjectAbstract::SCHEDULED_AT => $scheduledAt,
                    SmsCampaignDomainObjectAbstract::AUTO_KEY => $key,
                    SmsCampaignDomainObjectAbstract::AUTO_EDITED => false,
                ]);
            } catch (UniqueConstraintViolationException) {
                continue;
            }
        }

        return $created;
    }

    /**
     * Follows a change of the event start or timezone, for the automatic reminders not yet sent nor edited by the organizer.
     */
    public function rescheduleForEvent(EventDomainObject $event): void
    {
        $reminders = $this->campaignRepository->findWhere([
            SmsCampaignDomainObjectAbstract::EVENT_ID => $event->getId(),
            SmsCampaignDomainObjectAbstract::TYPE => SmsCampaignType::REMINDER->value,
            SmsCampaignDomainObjectAbstract::STATUS => SmsCampaignStatus::SCHEDULED->value,
            SmsCampaignDomainObjectAbstract::AUTO_EDITED => false,
            [SmsCampaignDomainObjectAbstract::AUTO_KEY, 'not null', null],
        ]);

        /** @var SmsCampaignDomainObject $reminder */
        foreach ($reminders as $reminder) {
            $scheduledAt = self::scheduledAt((string) $reminder->getAutoKey(), $event);
            if ($scheduledAt === $reminder->getScheduledAt()) {
                continue;
            }

            $where = [
                SmsCampaignDomainObjectAbstract::ID => $reminder->getId(),
                SmsCampaignDomainObjectAbstract::STATUS => SmsCampaignStatus::SCHEDULED->value,
                SmsCampaignDomainObjectAbstract::AUTO_EDITED => false,
            ];

            if ($scheduledAt === null || ! $this->isInFuture($scheduledAt)) {
                $this->campaignRepository->updateWhere(
                    attributes: [
                        SmsCampaignDomainObjectAbstract::STATUS => SmsCampaignStatus::CANCELLED->value,
                        SmsCampaignDomainObjectAbstract::COMPLETED_AT => $this->now(),
                    ],
                    where: $where,
                );

                continue;
            }

            if ($this->campaignRepository->updateWhere([SmsCampaignDomainObjectAbstract::SCHEDULED_AT => $scheduledAt], $where) === 1) {
                $this->recipientRepository->updateWhere(
                    attributes: [SmsCampaignRecipientDomainObjectAbstract::SCHEDULED_AT => $scheduledAt],
                    where: [
                        SmsCampaignRecipientDomainObjectAbstract::SMS_CAMPAIGN_ID => $reminder->getId(),
                        SmsCampaignRecipientDomainObjectAbstract::STATUS => SmsCampaignRecipientStatus::PENDING->value,
                    ],
                );
            }
        }

        $this->createForEvent($event);
    }

    /**
     * Cancels the reminders of an event that are still waiting to be sent.
     *
     * @param  bool  $automaticOnly  true to keep the reminders the organizer scheduled by hand
     */
    public function cancelPendingForEvent(int $eventId, bool $automaticOnly = true): int
    {
        $where = [
            SmsCampaignDomainObjectAbstract::EVENT_ID => $eventId,
            SmsCampaignDomainObjectAbstract::TYPE => SmsCampaignType::REMINDER->value,
            SmsCampaignDomainObjectAbstract::STATUS => SmsCampaignStatus::SCHEDULED->value,
        ];

        if ($automaticOnly) {
            $where[] = [SmsCampaignDomainObjectAbstract::AUTO_KEY, 'not null', null];
        }

        return $this->campaignRepository->updateWhere(
            attributes: [
                SmsCampaignDomainObjectAbstract::STATUS => SmsCampaignStatus::CANCELLED->value,
                SmsCampaignDomainObjectAbstract::COMPLETED_AT => $this->now(),
            ],
            where: $where,
        );
    }

    /**
     * UTC date and time of sending, Y-m-d H:i:s.
     */
    public static function scheduledAt(string $key, EventDomainObject $event): ?string
    {
        if ($event->getStartDate() === null) {
            return null;
        }

        try {
            $start = Carbon::parse($event->getStartDate(), 'UTC')->setTimezone($event->getTimezone() ?: 'UTC');
        } catch (Throwable) {
            return null;
        }

        $local = match ($key) {
            self::KEY_WEEK_BEFORE => $start->copy()->subDays(7)->setTime(self::EVENING_HOUR, 0),
            self::KEY_DAY_BEFORE => $start->copy()->subDay()->setTime(self::EVENING_HOUR, 0),
            self::KEY_SAME_DAY => $start->copy()->subHours(self::SAME_DAY_HOURS_BEFORE)->setSecond(0),
            default => null,
        };

        return $local?->utc()->toDateTimeString();
    }

    public static function name(string $key): string
    {
        return match ($key) {
            self::KEY_WEEK_BEFORE => __('Reminder D-7', [], 'fr'),
            self::KEY_DAY_BEFORE => __('Reminder D-1', [], 'fr'),
            default => __('Reminder on the day', [], 'fr'),
        };
    }

    public static function message(string $key, CustomerAddressForm $addressForm, string $organizerName): string
    {
        $locale = $addressForm === CustomerAddressForm::TU ? CustomerLocale::FRENCH_TUTOIEMENT : 'fr';

        $text = match ($key) {
            self::KEY_WEEK_BEFORE => __('{prenom}, only one week to go before {evenement} on {date} at {heure}. Manage your attendance: {lien}', [], $locale),
            self::KEY_DAY_BEFORE => __("{prenom}, it's tomorrow! {evenement} on {date} at {heure}. Manage your attendance: {lien}", [], $locale),
            default => __("{prenom}, it's today! {evenement} at {heure}. See you soon!", [], $locale),
        };

        $signature = trim($organizerName);

        return $signature !== '' ? $text."\n\n".$signature.' via PICHA Ticket' : $text;
    }

    private function safely(string $operation, int $eventId, callable $callback): void
    {
        $this->databaseManager->afterCommit(function () use ($operation, $eventId, $callback) {
            try {
                $callback();
            } catch (Throwable $exception) {
                $this->logger->error('Default SMS reminders could not be updated', [
                    'operation' => $operation,
                    'event_id' => $eventId,
                    'error' => $exception->getMessage(),
                ]);
            }
        });
    }

    private function isEnabled(int $organizerId): bool
    {
        /** @var OrganizerSettingDomainObject|null $settings */
        $settings = $this->organizerSettingsRepository->findFirstWhere([
            OrganizerSettingDomainObjectAbstract::ORGANIZER_ID => $organizerId,
        ]);

        return (bool) $settings?->getSmsDefaultRemindersEnabled();
    }

    private function findAutoReminder(int $eventId, string $key): ?SmsCampaignDomainObject
    {
        return $this->campaignRepository->findFirstWhere([
            SmsCampaignDomainObjectAbstract::EVENT_ID => $eventId,
            SmsCampaignDomainObjectAbstract::AUTO_KEY => $key,
        ]);
    }

    private function isInFuture(string $scheduledAt): bool
    {
        return Carbon::parse($scheduledAt, 'UTC')->gt(Carbon::now('UTC'));
    }

    private function now(): string
    {
        return Carbon::now('UTC')->toDateTimeString();
    }
}
