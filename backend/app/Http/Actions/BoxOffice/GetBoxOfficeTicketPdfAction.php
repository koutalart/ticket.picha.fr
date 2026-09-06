<?php

namespace HiEvents\Http\Actions\BoxOffice;

use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\Generated\AttendeeDomainObjectAbstract;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\ResponseCodes;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Services\Domain\Ticket\AttendeeTicketPdfService;
use Illuminate\Http\Response;

class GetBoxOfficeTicketPdfAction extends BaseAction
{
    public function __construct(
        private readonly AttendeeRepositoryInterface $attendeeRepository,
        private readonly EventRepositoryInterface    $eventRepository,
        private readonly AttendeeTicketPdfService    $attendeeTicketPdfService,
    )
    {
    }

    public function __invoke(int $eventId, string $attendeePublicId): Response
    {
        $this->isBoxOfficeActionAuthorized($eventId);

        $attendee = $this->attendeeRepository->findFirstWhere([
            AttendeeDomainObjectAbstract::PUBLIC_ID => $attendeePublicId,
            AttendeeDomainObjectAbstract::EVENT_ID => $eventId,
        ]);

        if (!$attendee) {
            return $this->notFoundResponse();
        }

        $event = $this->eventRepository
            ->loadRelation(new Relationship(OrganizerDomainObject::class, name: 'organizer'))
            ->loadRelation(new Relationship(EventSettingDomainObject::class))
            ->findById($eventId);

        $pdf = $this->attendeeTicketPdfService->generate(
            $attendee,
            $event,
            $event->getEventSettings(),
            $event->getOrganizer(),
        );

        return response($pdf, ResponseCodes::HTTP_OK)->header('Content-Type', 'application/pdf');
    }
}
