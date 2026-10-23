<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\OrganizerCalendar;

use HiEvents\Exceptions\OrganizerCalendarAccessDeniedException;
use HiEvents\Exceptions\OrganizerCalendarNotFoundException;
use HiEvents\Services\Application\Handlers\OrganizerCalendar\DTO\OrganizerCalendarContentDTO;
use HiEvents\Services\Domain\OrganizerCalendar\OrganizerCalendarAccessService;
use HiEvents\Services\Domain\OrganizerCalendar\OrganizerCalendarContentResolver;
use HiEvents\Services\Domain\OrganizerCalendar\OrganizerCalendarResolver;
use Illuminate\Support\Carbon;

class GetOrganizerCalendarContentHandler
{
    public function __construct(
        private readonly OrganizerCalendarResolver $calendarResolver,
        private readonly OrganizerCalendarAccessService $accessService,
        private readonly OrganizerCalendarContentResolver $contentResolver,
    ) {}

    /**
     * @throws OrganizerCalendarNotFoundException
     * @throws OrganizerCalendarAccessDeniedException
     */
    public function handle(string $slug, ?string $accessKey): OrganizerCalendarContentDTO
    {
        $calendar = $this->calendarResolver->findBySlug($slug);
        $access = $this->accessService->resolve($calendar->getId(), $accessKey);
        $expiresAt = Carbon::parse($access->getExpiresAt());

        return new OrganizerCalendarContentDTO(
            content: $this->contentResolver->resolve($calendar, $access->getId(), $expiresAt),
            expiresAt: $expiresAt,
        );
    }
}
