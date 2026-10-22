<?php

namespace HiEvents\Http\Actions\Attendees\Attendance;

use HiEvents\Exceptions\AttendanceAccessRateLimitedException;
use HiEvents\Exceptions\AttendanceResponseNotAllowedException;
use HiEvents\Exceptions\InvalidAttendanceAccessCodeException;
use HiEvents\Exceptions\InvalidAttendanceAccessContactException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\Attendee\VerifyAttendanceAccessCodeRequest;
use HiEvents\Http\ResponseCodes;
use HiEvents\Services\Application\Handlers\Attendee\AttendanceAccess\DTO\AttendanceAccessLinkDTO;
use HiEvents\Services\Application\Handlers\Attendee\AttendanceAccess\DTO\VerifyAttendanceAccessCodeDTO;
use HiEvents\Services\Application\Handlers\Attendee\AttendanceAccess\VerifyAttendanceAccessCodeHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class VerifyAttendanceAccessCodeActionPublic extends BaseAction
{
    public function __construct(
        private readonly VerifyAttendanceAccessCodeHandler $handler,
    ) {}

    public function __invoke(VerifyAttendanceAccessCodeRequest $request, int $eventId): JsonResponse
    {
        try {
            $links = $this->handler->handle(new VerifyAttendanceAccessCodeDTO(
                eventId: $eventId,
                contact: (string) $request->validated('contact'),
                code: (string) $request->validated('code'),
                ipAddress: (string) $request->ip(),
            ));
        } catch (InvalidAttendanceAccessContactException $exception) {
            throw ValidationException::withMessages(['contact' => $exception->getMessage()]);
        } catch (InvalidAttendanceAccessCodeException $exception) {
            throw ValidationException::withMessages(['code' => $exception->getMessage()]);
        } catch (AttendanceAccessRateLimitedException $exception) {
            return $this->errorResponse($exception->getMessage(), ResponseCodes::HTTP_TOO_MANY_REQUESTS);
        } catch (AttendanceResponseNotAllowedException $exception) {
            return $this->errorResponse($exception->getMessage(), ResponseCodes::HTTP_FORBIDDEN);
        }

        return $this->jsonResponse(
            data: $links->map(fn (AttendanceAccessLinkDTO $link) => $link->toArray())->all(),
            wrapInData: true,
        );
    }
}
