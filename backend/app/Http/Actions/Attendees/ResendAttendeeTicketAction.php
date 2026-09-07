<?php

namespace HiEvents\Http\Actions\Attendees;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\Generated\AttendeeDomainObjectAbstract;
use HiEvents\Exceptions\AttendeeEmailMissingException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\ResponseCodes;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Services\Application\Handlers\Attendee\DTO\ResendAttendeeTicketDTO;
use HiEvents\Services\Application\Handlers\Attendee\ResendAttendeeTicketHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;

class ResendAttendeeTicketAction extends BaseAction
{
    public function __construct(
        private readonly ResendAttendeeTicketHandler $handler,
        private readonly AttendeeRepositoryInterface $attendeeRepository,
    ) {
    }

    public function __invoke(int $eventId, string $attendeePublicId): JsonResponse|Response
    {
        $this->isActionAuthorized($eventId, EventDomainObject::class);

        $attendee = $this->attendeeRepository->findFirstWhere([
            AttendeeDomainObjectAbstract::PUBLIC_ID => $attendeePublicId,
            AttendeeDomainObjectAbstract::EVENT_ID => $eventId,
        ]);

        if ($attendee === null && ctype_digit($attendeePublicId)) {
            $attendee = $this->attendeeRepository->findFirstWhere([
                AttendeeDomainObjectAbstract::ID => (int) $attendeePublicId,
                AttendeeDomainObjectAbstract::EVENT_ID => $eventId,
            ]);
        }

        if ($attendee === null) {
            return $this->errorResponse(__('Attendee not found.'), Response::HTTP_CONFLICT);
        }

        if ($attendee->getEmail() === null || $attendee->getEmail() === '') {
            return $this->errorResponse(
                __('Ce participant n\'a pas encore d\'e-mail. Renseignez-le d\'abord.'),
                ResponseCodes::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        try {
            $this->handler->handle(new ResendAttendeeTicketDTO(
                attendeeId: $attendee->getId(),
                eventId: $eventId
            ));
        } catch (ResourceNotFoundException $e) {
            return $this->errorResponse($e->getMessage(), Response::HTTP_CONFLICT);
        } catch (AttendeeEmailMissingException $e) {
            return $this->errorResponse($e->getMessage(), ResponseCodes::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->noContentResponse();
    }
}
