<?php

namespace HiEvents\Services\Application\Handlers\Booth;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\Status\AttendeeStatus;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Services\Application\Handlers\Booth\DTO\BoothTicketContactsDTO;

/**
 * Contact used by PICHA Booth to send a one-time code: the attendee's own phone/e-mail when set,
 * otherwise the buyer's (order). Read only: never checks the attendee in.
 */
class GetBoothTicketContactsHandler
{
    public function __construct(
        private readonly AttendeeRepositoryInterface $attendeeRepository,
        private readonly OrderRepositoryInterface $orderRepository,
    ) {}

    public function handle(int $eventId, string $attendeePublicId): ?BoothTicketContactsDTO
    {
        /** @var AttendeeDomainObject|null $attendee */
        $attendee = $this->attendeeRepository->findFirstWhere([
            'event_id' => $eventId,
            'public_id' => $attendeePublicId,
        ]);

        if ($attendee === null || $attendee->getStatus() !== AttendeeStatus::ACTIVE->name) {
            return null;
        }

        /** @var OrderDomainObject|null $order */
        $order = $this->orderRepository->findFirstWhere([
            'id' => $attendee->getOrderId(),
            'event_id' => $eventId,
        ]);

        return new BoothTicketContactsDTO(
            phone: $this->firstFilled($attendee->getPhone(), $order?->getPhone()),
            email: $this->firstFilled($attendee->getEmail(), $order?->getEmail()),
        );
    }

    private function firstFilled(?string ...$values): ?string
    {
        foreach ($values as $value) {
            if ($value !== null && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }
}
