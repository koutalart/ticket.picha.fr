<?php

namespace HiEvents\Http\Actions\OrganizerCalendars;

use HiEvents\Exceptions\InvalidOrganizerCalendarCodeException;
use HiEvents\Exceptions\OrganizerCalendarNotFoundException;
use HiEvents\Exceptions\OrganizerCalendarRateLimitedException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\OrganizerCalendar\VerifyOrganizerCalendarCodeRequest;
use HiEvents\Http\ResponseCodes;
use HiEvents\Services\Application\Handlers\OrganizerCalendar\VerifyOrganizerCalendarCodeHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * The access key is returned as "jeton": a field named "token" would be read by jwt-auth as a session token.
 */
class VerifyOrganizerCalendarCodeActionPublic extends BaseAction
{
    public function __construct(
        private readonly VerifyOrganizerCalendarCodeHandler $handler,
    ) {}

    public function __invoke(VerifyOrganizerCalendarCodeRequest $request, string $slug): JsonResponse|Response
    {
        try {
            $grant = $this->handler->handle(
                $slug,
                (string) $request->validated('code'),
                (string) $this->getClientIp($request),
            );
        } catch (OrganizerCalendarNotFoundException) {
            return $this->notFoundResponse();
        } catch (OrganizerCalendarRateLimitedException $exception) {
            return $this->jsonResponse([
                'message' => $exception->getMessage(),
                'retry_after' => $exception->retryAfterSeconds,
            ], ResponseCodes::HTTP_TOO_MANY_REQUESTS)->header('Retry-After', (string) $exception->retryAfterSeconds);
        } catch (InvalidOrganizerCalendarCodeException $exception) {
            throw ValidationException::withMessages(['code' => $exception->getMessage()]);
        }

        return $this->jsonResponse([
            'jeton' => $grant->accessKey,
            'expire_le' => $grant->expiresAt->toIso8601String(),
        ])->header('Cache-Control', 'no-store');
    }
}
