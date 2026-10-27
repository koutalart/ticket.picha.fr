<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Email\Inbox;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\WaitlistEntryDomainObject;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Services\Domain\Email\Inbox\DTO\OutgoingEmailContextDTO;

/**
 * Finds the organizer, event, order and attendee an e-mail is about from the data handed to its view.
 */
class OutgoingEmailContextResolver
{
    public function __construct(
        private readonly OrganizerRepositoryInterface $organizerRepository,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function resolve(array $data): ?OutgoingEmailContextDTO
    {
        $organizer = $data['organizer'] ?? null;
        $event = $data['event'] ?? null;
        $order = $data['order'] ?? null;
        $attendee = $data['attendee'] ?? null;
        $entry = $data['entry'] ?? null;

        $organizer = $organizer instanceof OrganizerDomainObject ? $organizer : null;
        $event = $event instanceof EventDomainObject ? $event : null;
        $order = $order instanceof OrderDomainObject ? $order : null;
        $attendee = $attendee instanceof AttendeeDomainObject ? $attendee : null;
        $entry = $entry instanceof WaitlistEntryDomainObject ? $entry : null;

        $organizerId = $organizer?->getId() ?? $event?->getOrganizerId();
        $accountId = $organizer?->getAccountId() ?? $event?->getAccountId();

        if ($event !== null && $organizer !== null && $event->getOrganizerId() !== null && $event->getOrganizerId() !== $organizer->getId()) {
            return null;
        }

        if ($organizerId === null) {
            return null;
        }

        if ($accountId === null) {
            /** @var OrganizerDomainObject|null $loaded */
            $loaded = $this->organizerRepository->findFirst($organizerId);
            $accountId = $loaded?->getAccountId();
        }

        if ($accountId === null) {
            return null;
        }

        $name = null;
        if ($attendee !== null) {
            $name = trim($attendee->getFirstName().' '.$attendee->getLastName());
        } elseif ($order !== null) {
            $name = trim(($order->getFirstName() ?? '').' '.($order->getLastName() ?? ''));
        } elseif ($entry !== null) {
            $name = trim($entry->getFirstName().' '.($entry->getLastName() ?? ''));
        }

        return new OutgoingEmailContextDTO(
            organizerId: $organizerId,
            accountId: $accountId,
            eventId: $event?->getId() ?? $order?->getEventId() ?? $attendee?->getEventId(),
            orderId: $order?->getId() ?? $attendee?->getOrderId() ?? $entry?->getOrderId(),
            attendeeId: $attendee?->getId(),
            contactName: $name !== null && $name !== '' ? $name : null,
        );
    }
}
