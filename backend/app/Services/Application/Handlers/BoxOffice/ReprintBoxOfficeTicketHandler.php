<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\BoxOffice;

use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\Generated\PrintJobDomainObjectAbstract;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\PrintJobRepositoryInterface;
use HiEvents\Services\Application\Handlers\BoxOffice\DTO\ReprintBoxOfficeTicketDTO;
use HiEvents\Services\Domain\Ticket\AttendeeTicketPdfService;

/**
 * D14 (PICHA_BOX_OFFICE_DECISIONS_REQUIRED.md): reprint always relates to
 * the SAME attendee — never a new Order/Attendee — and is traced in
 * print_jobs for audit.
 */
class ReprintBoxOfficeTicketHandler
{
    public function __construct(
        private readonly AttendeeRepositoryInterface $attendeeRepository,
        private readonly EventRepositoryInterface $eventRepository,
        private readonly PrintJobRepositoryInterface $printJobRepository,
        private readonly AttendeeTicketPdfService $attendeeTicketPdfService,
    ) {}

    public function handle(ReprintBoxOfficeTicketDTO $dto): string
    {
        $attendee = $this->attendeeRepository->findById($dto->attendee_id);

        $event = $this->eventRepository
            ->loadRelation(new Relationship(OrganizerDomainObject::class, name: 'organizer'))
            ->loadRelation(new Relationship(EventSettingDomainObject::class))
            ->findById($dto->event_id);

        $pdf = $this->attendeeTicketPdfService->generate(
            $attendee,
            $event,
            $event->getEventSettings(),
            $event->getOrganizer(),
        );

        $this->printJobRepository->create([
            PrintJobDomainObjectAbstract::ATTENDEE_ID => $dto->attendee_id,
            PrintJobDomainObjectAbstract::AGENT_USER_ID => $dto->agent_user_id,
            PrintJobDomainObjectAbstract::PRINTED_AT => now()->toDateTimeString(),
        ]);

        return $pdf;
    }
}
