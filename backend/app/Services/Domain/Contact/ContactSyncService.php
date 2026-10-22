<?php

namespace HiEvents\Services\Domain\Contact;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\Repository\Interfaces\AttendeeCheckInRepositoryInterface;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\ContactRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;

class ContactSyncService
{
    public function __construct(
        private readonly ContactRepositoryInterface $contactRepository,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly AttendeeRepositoryInterface $attendeeRepository,
        private readonly AttendeeCheckInRepositoryInterface $attendeeCheckInRepository,
        private readonly EventRepositoryInterface $eventRepository,
        private readonly OrganizerRepositoryInterface $organizerRepository,
    ) {}

    public static function normalizeEmail(?string $email): ?string
    {
        if ($email === null) {
            return null;
        }

        $normalized = mb_strtolower(trim($email));

        if ($normalized === '' || str_ends_with($normalized, '.invalid')) {
            return null;
        }

        return $normalized;
    }

    public function syncForOrder(int $orderId): int
    {
        /** @var OrderDomainObject|null $order */
        $order = $this->orderRepository->findFirst($orderId);
        if ($order === null) {
            return 0;
        }

        /** @var EventDomainObject|null $event */
        $event = $this->eventRepository->findFirst($order->getEventId());
        if ($event === null || $event->getOrganizerId() === null) {
            return 0;
        }

        $emails = $this->attendeeRepository
            ->findWhere([AttendeeDomainObject::ORDER_ID => $orderId])
            ->map(fn (AttendeeDomainObject $attendee) => $attendee->getEmail())
            ->push($order->getEmail())
            ->map(fn (?string $email) => self::normalizeEmail($email))
            ->filter()
            ->unique()
            ->values()
            ->all();

        return $this->contactRepository->syncFromOrders($event->getOrganizerId(), $emails);
    }

    public function syncForAttendee(int $attendeeId): int
    {
        $attendee = $this->attendeeRepository->findFirst($attendeeId);

        return $attendee?->getOrderId() === null ? 0 : $this->syncForOrder($attendee->getOrderId());
    }

    public function syncForCheckIn(int $checkInId): int
    {
        $checkIn = $this->attendeeCheckInRepository->includeDeleted()->findFirst($checkInId);

        return $checkIn?->getOrderId() === null ? 0 : $this->syncForOrder($checkIn->getOrderId());
    }

    public function syncOrganizer(int $organizerId): int
    {
        return $this->contactRepository->syncFromOrders($organizerId);
    }

    /**
     * @return array<int, int> synced contacts per organizer id
     */
    public function syncAllOrganizers(): array
    {
        $results = [];

        foreach ($this->organizerRepository->all(['id']) as $organizer) {
            $results[$organizer->getId()] = $this->syncOrganizer($organizer->getId());
        }

        return $results;
    }
}
