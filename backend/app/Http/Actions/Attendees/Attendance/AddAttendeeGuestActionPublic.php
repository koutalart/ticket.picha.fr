<?php

namespace HiEvents\Http\Actions\Attendees\Attendance;

use HiEvents\Exceptions\AttendanceResponseNotAllowedException;
use HiEvents\Exceptions\AttendeeGuestNotAllowedException;
use HiEvents\Exceptions\InvalidAttendanceCodeException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\Attendee\AddAttendeeGuestPublicRequest;
use HiEvents\Http\ResponseCodes;
use HiEvents\Resources\Attendee\AttendeeAttendanceResourcePublic;
use HiEvents\Services\Application\Handlers\Attendee\AddAttendeeGuestPublicHandler;
use HiEvents\Services\Application\Handlers\Attendee\DTO\AddAttendeeGuestPublicDTO;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class AddAttendeeGuestActionPublic extends BaseAction
{
    public function __construct(
        private readonly AddAttendeeGuestPublicHandler $handler,
    )
    {
    }

    public function __invoke(AddAttendeeGuestPublicRequest $request, int $eventId, string $attendeeShortId): JsonResponse
    {
        $phone = $request->validated('phone');

        try {
            $status = $this->handler->handle(new AddAttendeeGuestPublicDTO(
                eventId: $eventId,
                attendeeShortId: $attendeeShortId,
                code: (string)$request->validated('code'),
                firstName: (string)$request->validated('first_name'),
                lastName: (string)$request->validated('last_name'),
                phone: is_string($phone) && trim($phone) !== '' ? $phone : null,
            ));
        } catch (InvalidAttendanceCodeException $exception) {
            return $this->errorResponse($exception->getMessage(), ResponseCodes::HTTP_NOT_FOUND);
        } catch (AttendanceResponseNotAllowedException $exception) {
            return $this->errorResponse($exception->getMessage(), ResponseCodes::HTTP_FORBIDDEN);
        } catch (AttendeeGuestNotAllowedException $exception) {
            throw ValidationException::withMessages(['guest' => $exception->getMessage()]);
        }

        return $this->resourceResponse(AttendeeAttendanceResourcePublic::class, $status, ResponseCodes::HTTP_CREATED);
    }
}
