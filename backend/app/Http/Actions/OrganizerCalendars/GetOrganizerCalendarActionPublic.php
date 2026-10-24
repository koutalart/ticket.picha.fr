<?php

namespace HiEvents\Http\Actions\OrganizerCalendars;

use HiEvents\Exceptions\OrganizerCalendarAccessDeniedException;
use HiEvents\Exceptions\OrganizerCalendarNotFoundException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\ResponseCodes;
use HiEvents\Services\Application\Handlers\OrganizerCalendar\GetOrganizerCalendarContentHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The calendar content, its media replaced by signed URLs valid until the access expires.
 */
class GetOrganizerCalendarActionPublic extends BaseAction
{
    public const ACCESS_HEADER = 'X-Acces-Calendrier';

    public function __construct(
        private readonly GetOrganizerCalendarContentHandler $handler,
    ) {}

    public function __invoke(Request $request, string $slug): JsonResponse|Response
    {
        try {
            $calendar = $this->handler->handle(
                $slug,
                $request->header(self::ACCESS_HEADER),
                $this->getClientIp($request),
                $request->userAgent(),
            );
        } catch (OrganizerCalendarNotFoundException) {
            return $this->notFoundResponse();
        } catch (OrganizerCalendarAccessDeniedException $exception) {
            return $this->errorResponse($exception->getMessage(), ResponseCodes::HTTP_UNAUTHORIZED)
                ->header('Cache-Control', 'no-store');
        }

        return $this->jsonResponse(array_merge($calendar->content, [
            'expire_le' => $calendar->expiresAt->toIso8601String(),
        ]))->header('Cache-Control', 'no-store');
    }
}
