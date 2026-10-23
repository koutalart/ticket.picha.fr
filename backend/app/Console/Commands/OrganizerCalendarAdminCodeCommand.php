<?php

namespace HiEvents\Console\Commands;

use HiEvents\Exceptions\OrganizerCalendarNotFoundException;
use HiEvents\Services\Domain\OrganizerCalendar\OrganizerCalendarAdminCodeService;
use HiEvents\Services\Domain\OrganizerCalendar\OrganizerCalendarResolver;
use Illuminate\Console\Command;

/**
 * The code is printed in this terminal only, once: it is stored hashed and never logged.
 */
class OrganizerCalendarAdminCodeCommand extends Command
{
    protected $signature = 'calendar:admin-code
        {slug : Slug of the calendar}
        {--revoke : Remove the admin code instead of generating a new one}';

    protected $description = 'Generate (or revoke) the admin access code of a private organizer calendar; a new code replaces the previous one';

    public function __construct(
        private readonly OrganizerCalendarResolver $calendarResolver,
        private readonly OrganizerCalendarAdminCodeService $adminCodeService,
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

        if ($this->option('revoke')) {
            $this->adminCodeService->revoke($calendar);
            $this->info('Admin code removed. Accesses already opened with it stay valid until they expire (calendar:revoke cuts them).');

            return self::SUCCESS;
        }

        $code = $this->adminCodeService->generate($calendar);

        $this->info('New admin code for "'.$calendar->getTitle().'" (the previous one no longer works):');
        $this->line('');
        $this->line('    '.$code);
        $this->line('');
        $this->warn('It is shown only now and cannot be found again. Open /calendrier/'.$calendar->getSlug().'?admin and type it (dashes optional).');

        return self::SUCCESS;
    }
}
