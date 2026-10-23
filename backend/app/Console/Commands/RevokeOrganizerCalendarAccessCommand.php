<?php

namespace HiEvents\Console\Commands;

use HiEvents\Exceptions\OrganizerCalendarNotFoundException;
use HiEvents\Services\Domain\OrganizerCalendar\OrganizerCalendarAccessService;
use HiEvents\Services\Domain\OrganizerCalendar\OrganizerCalendarCodeService;
use HiEvents\Services\Domain\OrganizerCalendar\OrganizerCalendarResolver;
use Illuminate\Console\Command;

class RevokeOrganizerCalendarAccessCommand extends Command
{
    protected $signature = 'calendar:revoke {slug : Slug of the calendar}';

    protected $description = 'Revoke every open access (and pending code) of a private organizer calendar';

    public function __construct(
        private readonly OrganizerCalendarResolver $calendarResolver,
        private readonly OrganizerCalendarAccessService $accessService,
        private readonly OrganizerCalendarCodeService $codeService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $calendar = $this->calendarResolver->findBySlug((string) $this->argument('slug'));
        } catch (OrganizerCalendarNotFoundException) {
            $this->error('No calendar with this slug.');

            return self::FAILURE;
        }

        $accesses = $this->accessService->revokeAll($calendar->getId());
        $codes = $this->codeService->expireAll($calendar->getId());

        $this->info(sprintf('%d access(es) revoked, %d pending code(s) cancelled. A new SMS code is now needed.', $accesses, $codes));

        return self::SUCCESS;
    }
}
