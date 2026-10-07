<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\BoxOffice;

use HiEvents\DomainObjects\Generated\AttendeeDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\PrintJobDomainObjectAbstract;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\PrintJobRepositoryInterface;
use HiEvents\Services\Domain\BoxOffice\DTO\BoxOfficeTicketZplDTO;
use HiEvents\Services\Domain\Ticket\AttendeeTicketZplService;
use HiEvents\Services\Domain\Ticket\DTO\ZplLabelFormatDTO;
use HiEvents\Services\Domain\Ticket\TicketDataFactory;

/**
 * The box office prints the same ticket as the PDF and the online ticket (TicketLayoutBuilder, 8 x 8 cm, 203 dpi).
 */
class BoxOfficeTicketZplService
{
    public function __construct(
        private readonly AttendeeRepositoryInterface $attendeeRepository,
        private readonly PrintJobRepositoryInterface $printJobRepository,
        private readonly AttendeeTicketZplService $attendeeTicketZplService,
        private readonly TicketDataFactory $ticketDataFactory,
    ) {}

    /**
     * @throws ResourceNotFoundException
     */
    public function build(int $eventId, string $attendeePublicId, ?ZplLabelFormatDTO $labelFormat = null): BoxOfficeTicketZplDTO
    {
        $attendee = $this->attendeeRepository->findFirstWhere([
            AttendeeDomainObjectAbstract::PUBLIC_ID => $attendeePublicId,
            AttendeeDomainObjectAbstract::EVENT_ID => $eventId,
        ]);

        if ($attendee === null) {
            throw new ResourceNotFoundException(__('Attendee not found.'));
        }

        $previousLocale = app()->getLocale();
        app()->setLocale('fr');
        try {
            $zpl = $this->attendeeTicketZplService->generate($this->ticketDataFactory->forAttendee($attendee));
        } finally {
            app()->setLocale($previousLocale);
        }

        return new BoxOfficeTicketZplDTO(attendee_id: $attendee->getId(), zpl: $zpl);
    }

    public function recordPrintJob(int $attendeeId, int $agentUserId): void
    {
        $this->printJobRepository->create([
            PrintJobDomainObjectAbstract::ATTENDEE_ID => $attendeeId,
            PrintJobDomainObjectAbstract::AGENT_USER_ID => $agentUserId,
            PrintJobDomainObjectAbstract::PRINTED_AT => now()->toDateTimeString(),
        ]);
    }
}
