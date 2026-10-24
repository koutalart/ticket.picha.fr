<?php

namespace HiEvents\Console\Commands;

use HiEvents\DomainObjects\Enums\OrganizerCalendarAccessMode;
use HiEvents\Exceptions\OrganizerCalendarNotFoundException;
use HiEvents\Services\Domain\OrganizerCalendar\OrganizerCalendarAccessModeService;
use HiEvents\Services\Domain\OrganizerCalendar\OrganizerCalendarResolver;
use Illuminate\Console\Command;

class OrganizerCalendarAccessModeCommand extends Command
{
    protected $signature = 'calendar:access-mode
        {slug : Slug of the calendar}
        {mode : code (SMS code sent to the calendar phone) or name (first name only, no SMS)}';

    protected $description = 'Choose how visitors open a private organizer calendar';

    public function __construct(
        private readonly OrganizerCalendarResolver $calendarResolver,
        private readonly OrganizerCalendarAccessModeService $accessModeService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $mode = OrganizerCalendarAccessMode::tryFrom((string) $this->argument('mode'));

        if ($mode === null) {
            $this->error('Mode must be "code" or "name".');

            return self::FAILURE;
        }

        try {
            $calendar = $this->calendarResolver->findBySlug((string) $this->argument('slug'));
        } catch (OrganizerCalendarNotFoundException) {
            $this->error('No calendar with this slug.');

            return self::FAILURE;
        }

        $previous = OrganizerCalendarAccessModeService::modeOf($calendar);
        $this->accessModeService->setMode($calendar, $mode);

        $this->info(sprintf('"%s": access mode %s (was %s).', $calendar->getTitle(), $mode->value, $previous->value));
        $this->line($mode === OrganizerCalendarAccessMode::NAME
            ? 'Visitors type their first name and press Consulter: no SMS is sent. The admin code (?admin) still works.'
            : 'Visitors receive a one-time SMS code. The admin code (?admin) still works.');

        return self::SUCCESS;
    }
}
