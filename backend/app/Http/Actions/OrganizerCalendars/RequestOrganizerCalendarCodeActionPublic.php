<?php

namespace HiEvents\Http\Actions\OrganizerCalendars;

use HiEvents\Exceptions\OrganizerCalendarCodeDisabledException;
use HiEvents\Exceptions\OrganizerCalendarCodeUnavailableException;
use HiEvents\Exceptions\OrganizerCalendarNotFoundException;
use HiEvents\Exceptions\OrganizerCalendarRateLimitedException;
use HiEvents\Helper\CustomerLocale;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\ResponseCodes;
use HiEvents\Services\Application\Handlers\OrganizerCalendar\RequestOrganizerCalendarCodeHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class RequestOrganizerCalendarCodeActionPublic extends BaseAction
{
    public function __construct(
        private readonly RequestOrganizerCalendarCodeHandler $handler,
    ) {}

    public function __invoke(Request $request, string $slug): JsonResponse|Response
    {
        try {
            $sent = $this->handler->handle($slug, (string) $this->getClientIp($request));
        } catch (OrganizerCalendarNotFoundException|OrganizerCalendarCodeDisabledException) {
            return $this->notFoundResponse();
        } catch (OrganizerCalendarRateLimitedException $exception) {
            return $this->jsonResponse([
                'message' => $exception->getMessage(),
                'retry_after' => $exception->retryAfterSeconds,
            ], ResponseCodes::HTTP_TOO_MANY_REQUESTS)->header('Retry-After', (string) $exception->retryAfterSeconds);
        } catch (OrganizerCalendarCodeUnavailableException $exception) {
            return $this->errorResponse($exception->getMessage(), ResponseCodes::HTTP_SERVICE_UNAVAILABLE);
        }

        return $this->jsonResponse([
            'message' => __('A code was sent by SMS to the number ending in :digits.', ['digits' => $sent->phoneLastDigits], CustomerLocale::get()),
            'telephone_masque' => $sent->maskedPhone,
            'expire_dans' => $sent->validitySeconds,
        ]);
    }
}
