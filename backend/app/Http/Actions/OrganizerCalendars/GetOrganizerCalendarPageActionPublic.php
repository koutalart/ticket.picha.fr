<?php

namespace HiEvents\Http\Actions\OrganizerCalendars;

use HiEvents\Exceptions\OrganizerCalendarNotFoundException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Services\Application\Handlers\OrganizerCalendar\GetOrganizerCalendarPageHandler;
use Illuminate\Http\Response;

/**
 * The page shell only (no calendar data), relayed as is by the frontend server at /calendrier/{slug}.
 */
class GetOrganizerCalendarPageActionPublic extends BaseAction
{
    public function __construct(
        private readonly GetOrganizerCalendarPageHandler $handler,
    ) {}

    public function __invoke(string $slug): Response
    {
        try {
            $html = $this->handler->handle($slug);
        } catch (OrganizerCalendarNotFoundException) {
            return $this->notFoundResponse();
        }

        return $this->htmlResponse($html, headers: [
            'Cache-Control' => 'no-store',
            'X-Robots-Tag' => 'noindex, nofollow',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
