<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms\CheckInWelcome;

use HiEvents\DomainObjects\Enums\SmsMessageAuthorType;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\Generated\EventDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\OrganizerSmsSettingDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\SmsCheckinWelcomeDomainObjectAbstract;
use HiEvents\DomainObjects\OrganizerSmsSettingDomainObject;
use HiEvents\DomainObjects\SmsCheckinWelcomeDomainObject;
use HiEvents\DomainObjects\Status\SmsCheckinWelcomeStatus;
use HiEvents\Helper\SmsPhoneNormalizer;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerSmsSettingRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsCheckinWelcomeRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsCustomerDirectoryRepositoryInterface;
use HiEvents\Services\Domain\Sms\DTO\SendSmsDTO;
use HiEvents\Services\Domain\Sms\DTO\SmsReminderAttendeeDTO;
use HiEvents\Services\Domain\Sms\SmsSendService;
use Illuminate\Database\UniqueConstraintViolationException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Sends a welcome SMS when an attendee is checked in for the first time, if the organizer turned it on.
 * The phone is the attendee's, else the order's. Without one (e.g. paper « Sur place » tickets) nothing happens.
 * A phone receives at most one welcome per event: the unique index sms_checkin_welcomes_one_per_phone settles groups and concurrent scans.
 */
class SmsCheckInWelcomeService
{
    public function __construct(
        private readonly EventRepositoryInterface $eventRepository,
        private readonly OrganizerSmsSettingRepositoryInterface $settingsRepository,
        private readonly SmsCustomerDirectoryRepositoryInterface $directoryRepository,
        private readonly SmsCheckinWelcomeRepositoryInterface $welcomeRepository,
        private readonly SmsCheckInWelcomeMessageGenerator $messageGenerator,
        private readonly SmsSendService $sendService,
        private readonly LoggerInterface $logger,
    ) {}

    public function sendForAttendee(int $eventId, int $attendeeId): ?SmsCheckinWelcomeDomainObject
    {
        /** @var EventDomainObject|null $event */
        $event = $this->eventRepository->findFirstWhere([EventDomainObjectAbstract::ID => $eventId]);
        if ($event === null || $event->getOrganizerId() === null || ! $this->isEnabled($event->getOrganizerId())) {
            return null;
        }

        /** @var SmsReminderAttendeeDTO|null $attendee */
        $attendee = $this->directoryRepository->findAttendeesWithPhone($eventId, [$attendeeId])->first();
        $phone = $attendee?->phone !== null ? SmsPhoneNormalizer::normalize($attendee->phone) : null;
        $firstName = trim((string) $attendee?->firstName);
        if ($phone === null || $firstName === '') {
            return null;
        }

        $welcome = $this->claim($event, $attendeeId, $phone);
        if ($welcome === null) {
            return null;
        }

        try {
            $message = $this->messageGenerator->generate($firstName, $event->getTitle());

            $sms = $this->sendService->send(new SendSmsDTO(
                organizerId: $event->getOrganizerId(),
                phone: $phone,
                content: $message->content,
                authorType: SmsMessageAuthorType::AUTOMATIC,
            ));

            return $this->welcomeRepository->updateFromArray($welcome->getId(), [
                SmsCheckinWelcomeDomainObjectAbstract::STATUS => SmsCheckinWelcomeStatus::SENT->name,
                SmsCheckinWelcomeDomainObjectAbstract::SOURCE => $message->source,
                SmsCheckinWelcomeDomainObjectAbstract::SMS_MESSAGE_ID => $sms->getId(),
            ]);
        } catch (Throwable $exception) {
            $this->logger->warning('Check-in welcome SMS not sent', [
                'event_id' => $eventId,
                'attendee_id' => $attendeeId,
                'error' => $exception->getMessage(),
            ]);

            return $this->welcomeRepository->updateFromArray($welcome->getId(), [
                SmsCheckinWelcomeDomainObjectAbstract::STATUS => SmsCheckinWelcomeStatus::FAILED->name,
                SmsCheckinWelcomeDomainObjectAbstract::FAILURE_REASON => mb_substr($exception->getMessage(), 0, 500),
            ]);
        }
    }

    private function isEnabled(int $organizerId): bool
    {
        /** @var OrganizerSmsSettingDomainObject|null $settings */
        $settings = $this->settingsRepository->findFirstWhere([
            OrganizerSmsSettingDomainObjectAbstract::ORGANIZER_ID => $organizerId,
        ]);

        return $settings !== null && $settings->getCheckinWelcomeSmsEnabled();
    }

    /**
     * Null when this phone already got (or is getting) its welcome for the event.
     */
    private function claim(EventDomainObject $event, int $attendeeId, string $phone): ?SmsCheckinWelcomeDomainObject
    {
        $existing = $this->welcomeRepository->findFirstWhere([
            SmsCheckinWelcomeDomainObjectAbstract::EVENT_ID => $event->getId(),
            SmsCheckinWelcomeDomainObjectAbstract::PHONE => $phone,
        ]);
        if ($existing !== null) {
            return null;
        }

        try {
            return $this->welcomeRepository->create([
                SmsCheckinWelcomeDomainObjectAbstract::ORGANIZER_ID => $event->getOrganizerId(),
                SmsCheckinWelcomeDomainObjectAbstract::EVENT_ID => $event->getId(),
                SmsCheckinWelcomeDomainObjectAbstract::ATTENDEE_ID => $attendeeId,
                SmsCheckinWelcomeDomainObjectAbstract::PHONE => $phone,
                SmsCheckinWelcomeDomainObjectAbstract::STATUS => SmsCheckinWelcomeStatus::SENDING->name,
            ]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }
}
