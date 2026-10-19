<?php

namespace HiEvents\Http\Actions\BoxOffice\PrintRelay;

use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\BoxOffice\EnqueueKioskPrintJobsRequest;
use HiEvents\Http\ResponseCodes;
use HiEvents\Services\Application\Handlers\BoxOffice\PrintRelay\DTO\EnqueueKioskPrintJobsDTO;
use HiEvents\Services\Application\Handlers\BoxOffice\PrintRelay\EnqueueKioskPrintJobsHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class EnqueueKioskPrintJobsAction extends BaseAction
{
    public function __construct(
        private readonly EnqueueKioskPrintJobsHandler $handler,
    ) {}

    public function __invoke(EnqueueKioskPrintJobsRequest $request, int $eventId): JsonResponse|Response
    {
        $this->isBoxOfficeActionAuthorized($eventId);

        try {
            $jobIds = $this->handler->handle(new EnqueueKioskPrintJobsDTO(
                event_id: $eventId,
                attendee_public_ids: array_values($request->validated('attendee_public_ids')),
                user_id: $this->getAuthenticatedUser()->getId(),
                label_format: $request->labelFormat(),
            ));
        } catch (ResourceNotFoundException) {
            return $this->notFoundResponse();
        }

        return $this->jsonResponse(['job_ids' => $jobIds], ResponseCodes::HTTP_CREATED, wrapInData: true);
    }
}
