<?php

namespace HiEvents\Http\Actions\OrganizerCalendars;

use HiEvents\Exceptions\OrganizerCalendarConsultationUnavailableException;
use HiEvents\Exceptions\OrganizerCalendarNotFoundException;
use HiEvents\Exceptions\OrganizerCalendarRateLimitedException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\OrganizerCalendar\ConsultOrganizerCalendarRequest;
use HiEvents\Http\ResponseCodes;
use HiEvents\Services\Application\Handlers\OrganizerCalendar\ConsultOrganizerCalendarHandler;
use HiEvents\Services\Application\Handlers\OrganizerCalendar\DTO\ConsultOrganizerCalendarDTO;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Same response as /verifier ("jeton", "expire_le"): the page stores the key the same way.
 */
class ConsultOrganizerCalendarActionPublic extends BaseAction
{
    public function __construct(
        private readonly ConsultOrganizerCalendarHandler $handler,
    ) {}

    public function __invoke(ConsultOrganizerCalendarRequest $request, string $slug): JsonResponse|Response
    {
        try {
            $grant = $this->handler->handle(new ConsultOrganizerCalendarDTO(
                slug: $slug,
                visitorName: (string) $request->validated('prenom'),
                deviceId: (string) $request->validated('appareil'),
                ipAddress: (string) $this->getClientIp($request),
                userAgent: $request->userAgent(),
            ));
        } catch (OrganizerCalendarNotFoundException|OrganizerCalendarConsultationUnavailableException) {
            return $this->notFoundResponse();
        } catch (OrganizerCalendarRateLimitedException $exception) {
            return $this->jsonResponse([
                'message' => $exception->getMessage(),
                'retry_after' => $exception->retryAfterSeconds,
            ], ResponseCodes::HTTP_TOO_MANY_REQUESTS)->header('Retry-After', (string) $exception->retryAfterSeconds);
        }

        return $this->jsonResponse([
            'jeton' => $grant->accessKey,
            'expire_le' => $grant->expiresAt->toIso8601String(),
        ])->header('Cache-Control', 'no-store');
    }
}
