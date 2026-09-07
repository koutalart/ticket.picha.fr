<?php

namespace HiEvents\Http\Actions\BoxOffice;

use HiEvents\DomainObjects\Generated\AttendeeDomainObjectAbstract;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\ResponseCodes;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Services\Application\Handlers\BoxOffice\DTO\ReprintBoxOfficeTicketDTO;
use HiEvents\Services\Application\Handlers\BoxOffice\ReprintBoxOfficeTicketHandler;
use Illuminate\Http\Response;

class ReprintBoxOfficeTicketAction extends BaseAction
{
    public function __construct(
        private readonly AttendeeRepositoryInterface $attendeeRepository,
        private readonly ReprintBoxOfficeTicketHandler $reprintBoxOfficeTicketHandler,
    ) {}

    public function __invoke(int $eventId, string $attendeePublicId): Response
    {
        $this->isBoxOfficeActionAuthorized($eventId);

        $attendee = $this->attendeeRepository->findFirstWhere([
            AttendeeDomainObjectAbstract::PUBLIC_ID => $attendeePublicId,
            AttendeeDomainObjectAbstract::EVENT_ID => $eventId,
        ]);

        if (! $attendee) {
            return $this->notFoundResponse();
        }

        $pdf = $this->reprintBoxOfficeTicketHandler->handle(new ReprintBoxOfficeTicketDTO(
            attendee_id: $attendee->getId(),
            event_id: $eventId,
            agent_user_id: $this->getAuthenticatedUser()->getId(),
        ));

        return response($pdf, ResponseCodes::HTTP_OK)->header('Content-Type', 'application/pdf');
    }
}
