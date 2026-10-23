<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\OrganizerCalendar;

use HiEvents\Exceptions\OrganizerCalendarNotFoundException;
use HiEvents\Services\Domain\OrganizerCalendar\OrganizerCalendarResolver;

class GetOrganizerCalendarPageHandler
{
    public function __construct(
        private readonly OrganizerCalendarResolver $calendarResolver,
    ) {}

    /**
     * @throws OrganizerCalendarNotFoundException
     */
    public function handle(string $slug): string
    {
        $html = $this->calendarResolver->findBySlug($slug)->getFrontHtml();

        if ($html === null || trim($html) === '') {
            throw new OrganizerCalendarNotFoundException('Calendar page missing');
        }

        return $html;
    }
}
