<?php

namespace HiEvents\Http\Actions\OrganizerCalendars;

use HiEvents\Exceptions\OrganizerCalendarAccessDeniedException;
use HiEvents\Exceptions\OrganizerCalendarNotFoundException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\ResponseCodes;
use HiEvents\Services\Application\Handlers\OrganizerCalendar\DownloadOrganizerCalendarMediaHandler;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reached only through the temporary signed URLs of the calendar content (signed:relative middleware).
 */
class DownloadOrganizerCalendarMediaActionPublic extends BaseAction
{
    public function __construct(
        private readonly DownloadOrganizerCalendarMediaHandler $handler,
    ) {}

    public function __invoke(Request $request, string $slug, int $mediaId): Response
    {
        try {
            return $this->handler->handle(
                $slug,
                $mediaId,
                (int) $request->query('a'),
                (int) $request->query('expires'),
            );
        } catch (OrganizerCalendarNotFoundException) {
            return $this->notFoundResponse();
        } catch (OrganizerCalendarAccessDeniedException $exception) {
            return $this->errorResponse($exception->getMessage(), ResponseCodes::HTTP_UNAUTHORIZED);
        }
    }
}
