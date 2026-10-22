<?php

namespace HiEvents\Http\Actions\Attendees\Attendance;

use HiEvents\Exceptions\AttendanceAccessRateLimitedException;
use HiEvents\Exceptions\AttendanceResponseNotAllowedException;
use HiEvents\Exceptions\InvalidAttendanceAccessContactException;
use HiEvents\Helper\CustomerLocale;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\Attendee\RequestAttendanceAccessCodeRequest;
use HiEvents\Http\ResponseCodes;
use HiEvents\Services\Application\Handlers\Attendee\AttendanceAccess\DTO\RequestAttendanceAccessCodeDTO;
use HiEvents\Services\Application\Handlers\Attendee\AttendanceAccess\RequestAttendanceAccessCodeHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class RequestAttendanceAccessCodeActionPublic extends BaseAction
{
    public function __construct(
        private readonly RequestAttendanceAccessCodeHandler $handler,
    ) {}

    public function __invoke(RequestAttendanceAccessCodeRequest $request, int $eventId): JsonResponse
    {
        try {
            $this->handler->handle(new RequestAttendanceAccessCodeDTO(
                eventId: $eventId,
                contact: (string) $request->validated('contact'),
                ipAddress: (string) $request->ip(),
            ));
        } catch (InvalidAttendanceAccessContactException $exception) {
            throw ValidationException::withMessages(['contact' => $exception->getMessage()]);
        } catch (AttendanceAccessRateLimitedException $exception) {
            return $this->errorResponse($exception->getMessage(), ResponseCodes::HTTP_TOO_MANY_REQUESTS);
        } catch (AttendanceResponseNotAllowedException $exception) {
            return $this->errorResponse($exception->getMessage(), ResponseCodes::HTTP_FORBIDDEN);
        }

        return $this->jsonResponse([
            'message' => __('If a registration matches, you will receive a message with a code.', [], CustomerLocale::get()),
        ]);
    }
}
