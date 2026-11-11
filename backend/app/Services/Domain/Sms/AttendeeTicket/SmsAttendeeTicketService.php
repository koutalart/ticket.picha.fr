<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms\AttendeeTicket;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\Enums\CustomerAddressForm;
use HiEvents\DomainObjects\Enums\SmsMessageAuthorType;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\Generated\AttendeeDomainObjectAbstract;
use HiEvents\DomainObjects\Status\AttendeeStatus;
use HiEvents\Helper\CustomerLocale;
use HiEvents\Helper\SmsPhoneNormalizer;
use HiEvents\Helper\SmsSendingHours;
use HiEvents\Helper\Url;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Services\Domain\Customer\CustomerLocaleResolver;
use HiEvents\Services\Domain\Sms\DTO\SendSmsDTO;
use HiEvents\Services\Domain\Sms\Reminder\SmsReminderMessageRenderer;
use HiEvents\Services\Domain\Sms\SmsShortLinkService;
use HiEvents\Services\Domain\Sms\SmsWithLinkSender;
use Illuminate\Support\Carbon;

/**
 * Sends an attendee their ticket by SMS: the text, then the short link to the ticket alone in a second SMS.
 * Automatic SMS only leave between 8:00 and 20:00 in the event timezone.
 */
class SmsAttendeeTicketService
{
    public function __construct(
        private readonly AttendeeRepositoryInterface $attendeeRepository,
        private readonly EventRepositoryInterface $eventRepository,
        private readonly SmsReminderMessageRenderer $renderer,
        private readonly SmsShortLinkService $shortLinkService,
        private readonly SmsWithLinkSender $sender,
        private readonly CustomerLocaleResolver $customerLocaleResolver,
    ) {}

    public static function message(CustomerAddressForm $addressForm): string
    {
        return __(
            'Hello {prenom}, here is your ticket for {evenement} on {date}, welcome from {heure}. Your ticket: {lien}',
            [],
            $addressForm === CustomerAddressForm::TU ? CustomerLocale::FRENCH_TUTOIEMENT : 'fr',
        );
    }

    /**
     * Null when sending now is allowed, otherwise the next moment it is.
     */
    public static function nextAllowedTime(Carbon $now, string $timezone): ?Carbon
    {
        return SmsSendingHours::nextAllowedTime($now, $timezone);
    }

    public function timezoneForEvent(int $eventId): string
    {
        /** @var EventDomainObject|null $event */
        $event = $this->eventRepository->findFirst($eventId);

        return $event?->getTimezone() ?: 'UTC';
    }

    /**
     * Returns false when there is nothing to send: attendee gone or inactive, no own phone, or no organizer.
     */
    public function send(int $attendeeId, int $eventId): bool
    {
        /** @var AttendeeDomainObject|null $attendee */
        $attendee = $this->attendeeRepository->findFirstWhere([
            AttendeeDomainObjectAbstract::ID => $attendeeId,
            AttendeeDomainObjectAbstract::EVENT_ID => $eventId,
        ]);

        if ($attendee === null || $attendee->getStatus() !== AttendeeStatus::ACTIVE->name) {
            return false;
        }

        $phone = $attendee->getPhone() !== null ? SmsPhoneNormalizer::normalize($attendee->getPhone()) : null;

        /** @var EventDomainObject|null $event */
        $event = $this->eventRepository->findFirst($eventId);

        if ($phone === null || $event === null || $event->getOrganizerId() === null) {
            return false;
        }

        $template = self::message($this->customerLocaleResolver->addressFormForOrganizerId($event->getOrganizerId()));
        $link = $this->shortLinkService->shorten(
            $event,
            sprintf(Url::getPublicFrontEndUrlFromConfig(Url::ATTENDEE_TICKET, $event), $event->getId(), $attendee->getShortId()),
        );

        $this->sender->send(new SendSmsDTO(
            organizerId: $event->getOrganizerId(),
            phone: $phone,
            content: $this->renderer->render($template, $event, $attendee->getFirstName(), $link),
            authorType: SmsMessageAuthorType::AUTOMATIC,
        ), $link);

        return true;
    }
}
