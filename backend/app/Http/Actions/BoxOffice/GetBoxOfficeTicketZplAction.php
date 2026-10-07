<?php

namespace HiEvents\Http\Actions\BoxOffice;

use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\BoxOffice\BoxOfficeZplLabelFormatRequest;
use HiEvents\Services\Application\Handlers\BoxOffice\DTO\GetBoxOfficeTicketZplDTO;
use HiEvents\Services\Application\Handlers\BoxOffice\GetBoxOfficeTicketZplHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class GetBoxOfficeTicketZplAction extends BaseAction
{
    public function __construct(
        private readonly GetBoxOfficeTicketZplHandler $handler,
    ) {}

    public function __invoke(BoxOfficeZplLabelFormatRequest $request, int $eventId, string $attendeePublicId): JsonResponse|Response
    {
        $this->isBoxOfficeActionAuthorized($eventId);

        try {
            $zpl = $this->handler->handle(new GetBoxOfficeTicketZplDTO(
                event_id: $eventId,
                attendee_public_id: $attendeePublicId,
                agent_user_id: $this->getAuthenticatedUser()->getId(),
                label_format: $request->labelFormat(),
            ));
        } catch (ResourceNotFoundException) {
            return $this->notFoundResponse();
        }

        return $this->jsonResponse(['zpl' => $zpl], wrapInData: true);
    }
}
