<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Ticket;

use HiEvents\DomainObjects\Generated\AttendeeDomainObjectAbstract;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Services\Domain\Ticket\TicketDataFactory;
use HiEvents\Services\Domain\Ticket\TicketImageService;

class GetAttendeeTicketImageHandler
{
    public function __construct(
        private readonly AttendeeRepositoryInterface $attendeeRepository,
        private readonly TicketDataFactory $ticketDataFactory,
        private readonly TicketImageService $ticketImageService,
    ) {}

    /**
     * @throws ResourceNotFoundException
     */
    public function handle(int $eventId, string $attendeeShortId): string
    {
        $attendee = $this->attendeeRepository->findFirstWhere([
            AttendeeDomainObjectAbstract::SHORT_ID => $attendeeShortId,
            AttendeeDomainObjectAbstract::EVENT_ID => $eventId,
        ]);

        if ($attendee === null) {
            throw new ResourceNotFoundException(__('Attendee not found.'));
        }

        $previousLocale = app()->getLocale();
        if ($attendee->getLocale()) {
            app()->setLocale($attendee->getLocale());
        }

        try {
            return $this->ticketImageService->png($this->ticketDataFactory->forAttendee($attendee));
        } finally {
            app()->setLocale($previousLocale);
        }
    }

    public function preview(int $eventId): string
    {
        return $this->ticketImageService->png($this->ticketDataFactory->forPreview($eventId));
    }
}
