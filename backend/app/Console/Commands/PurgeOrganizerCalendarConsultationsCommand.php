<?php

declare(strict_types=1);

namespace HiEvents\Console\Commands;

use HiEvents\Services\Domain\OrganizerCalendar\OrganizerCalendarConsultationService;
use Illuminate\Console\Command;

class PurgeOrganizerCalendarConsultationsCommand extends Command
{
    protected $signature = 'calendar:purge-consultations';

    protected $description = 'Delete private calendar consultation logs older than 180 days';

    public function handle(OrganizerCalendarConsultationService $consultationService): int
    {
        $deleted = $consultationService->purgeOlderThan();

        $this->info(sprintf('%d consultation(s) deleted.', $deleted));

        return self::SUCCESS;
    }
}
