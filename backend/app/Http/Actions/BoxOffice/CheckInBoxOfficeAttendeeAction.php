<?php

namespace HiEvents\Http\Actions\BoxOffice;

use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Services\Application\Handlers\BoxOffice\BoxOfficeCheckInAttendeeHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class CheckInBoxOfficeAttendeeAction extends BaseAction
{
    public function __construct(
        private readonly BoxOfficeCheckInAttendeeHandler $handler,
    ) {}

    public function __invoke(Request $request, int $eventId, string $attendeePublicId): JsonResponse|Response
    {
        $this->isBoxOfficeActionAuthorized($eventId);

        try {
            $result = $this->handler->handle($eventId, $attendeePublicId, (string) $request->ip());
        } catch (ResourceNotFoundException) {
            return $this->notFoundResponse();
        }

        return $this->jsonResponse($result->toArray(), wrapInData: true);
    }
}
