<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms\CancellationConfirmation;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\ContactDomainObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\Generated\ContactDomainObjectAbstract;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\ContactRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsCustomerDirectoryRepositoryInterface;
use HiEvents\Services\Domain\Contact\ContactPersonalLinkService;
use HiEvents\Services\Domain\Contact\ContactSyncService;
use HiEvents\Services\Domain\Contact\SmsContactResolver;

/**
 * The event registration page with the contact's ?c= code, on the organizer's public address, so a new registration lands on the same contact.
 * Without a contact (found by phone, then by email), the attendee's attendance page is used as before.
 */
class ContactSmsCancellationLinkProvider implements SmsCancellationLinkProviderInterface
{
    public function __construct(
        private readonly SmsCustomerDirectoryRepositoryInterface $directoryRepository,
        private readonly AttendeeRepositoryInterface $attendeeRepository,
        private readonly ContactRepositoryInterface $contactRepository,
        private readonly SmsContactResolver $contactResolver,
        private readonly ContactPersonalLinkService $contactPersonalLinkService,
        private readonly AttendanceSmsCancellationLinkProvider $fallback,
    ) {}

    public function findForAttendee(int $attendeeId, EventDomainObject $event): ?string
    {
        $contact = $this->findContact($attendeeId, $event);

        if ($contact === null || (string) $contact->getPublicCode() === '') {
            return $this->fallback->findForAttendee($attendeeId, $event);
        }

        return $this->contactPersonalLinkService->getUrl($event->getEventUrl(), $contact);
    }

    private function findContact(int $attendeeId, EventDomainObject $event): ?ContactDomainObject
    {
        $organizerId = $event->getOrganizerId();

        $phone = $this->directoryRepository->findAttendeesWithPhone($event->getId(), [$attendeeId])->first()?->phone;
        $contact = $this->contactResolver->find($organizerId, $phone);
        if ($contact !== null) {
            return $contact;
        }

        /** @var AttendeeDomainObject|null $attendee */
        $attendee = $this->attendeeRepository->findFirst($attendeeId);
        $email = ContactSyncService::normalizeEmail($attendee?->getEmail());
        if ($email === null) {
            return null;
        }

        /** @var ContactDomainObject|null $contact */
        $contact = $this->contactRepository->findFirstWhere([
            ContactDomainObjectAbstract::ORGANIZER_ID => $organizerId,
            ContactDomainObjectAbstract::EMAIL => $email,
            [ContactDomainObjectAbstract::MERGED_INTO_CONTACT_ID, 'null', null],
        ]);

        return $contact;
    }
}
