<?php

namespace HiEvents\Console\Commands;

use HiEvents\Services\Application\Handlers\BoxOffice\PrintRelay\PurgeKioskPrintJobsHandler;
use Illuminate\Console\Command;

class PurgeKioskPrintJobsCommand extends Command
{
    protected $signature = 'kiosk:purge-print-jobs';

    protected $description = 'Delete kiosk print relay jobs older than 48 hours';

    public function handle(PurgeKioskPrintJobsHandler $handler): int
    {
        $deleted = $handler->handle();

        $this->info(sprintf('Deleted %d kiosk print job(s).', $deleted));

        return self::SUCCESS;
    }
}
