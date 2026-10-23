<?php

namespace HiEvents\Http\Actions\Attendees\Attendance;

use Closure;
use HiEvents\Exceptions\AttendanceResponseNotAllowedException;
use HiEvents\Exceptions\InvalidAttendanceCodeException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\ResponseCodes;
use HiEvents\Resources\Attendee\AttendeeAttendanceResourcePublic;
use HiEvents\Services\Application\Handlers\Attendee\DTO\AttendeeAttendancePublicDTO;
use HiEvents\Services\Application\Handlers\Attendee\DTO\AttendeeAttendanceStatusDTO;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

abstract class BaseAttendeeAttendanceActionPublic extends BaseAction
{
    protected function buildDTO(Request $request, int $eventId, string $attendeeShortId): AttendeeAttendancePublicDTO
    {
        $code = $request->input('code');

        return new AttendeeAttendancePublicDTO(
            eventId: $eventId,
            attendeeShortId: $attendeeShortId,
            code: is_string($code) ? $code : null,
        );
    }

    /**
     * @param Closure(): AttendeeAttendanceStatusDTO $callback
     */
    protected function respond(Closure $callback, int $notAllowedStatusCode): JsonResponse
    {
        try {
            return $this->resourceResponse(AttendeeAttendanceResourcePublic::class, $callback());
        } catch (InvalidAttendanceCodeException $exception) {
            return $this->errorResponse($exception->getMessage(), ResponseCodes::HTTP_NOT_FOUND);
        } catch (AttendanceResponseNotAllowedException $exception) {
            return $this->errorResponse($exception->getMessage(), $notAllowedStatusCode);
        }
    }
}
