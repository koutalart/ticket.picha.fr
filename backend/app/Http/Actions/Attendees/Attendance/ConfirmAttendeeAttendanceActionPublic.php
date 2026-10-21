<?php

namespace HiEvents\Http\Actions\Attendees\Attendance;

use HiEvents\Http\ResponseCodes;
use HiEvents\Services\Application\Handlers\Attendee\ConfirmAttendeeAttendancePublicHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConfirmAttendeeAttendanceActionPublic extends BaseAttendeeAttendanceActionPublic
{
    public function __construct(
        private readonly ConfirmAttendeeAttendancePublicHandler $handler,
    )
    {
    }

    public function __invoke(Request $request, int $eventId, string $attendeeShortId): JsonResponse
    {
        return $this->respond(
            fn() => $this->handler->handle($this->buildDTO($request, $eventId, $attendeeShortId)),
            ResponseCodes::HTTP_CONFLICT,
        );
    }
}
