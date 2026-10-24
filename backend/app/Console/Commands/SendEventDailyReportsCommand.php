<?php

namespace HiEvents\Console\Commands;

use HiEvents\Services\Domain\Event\DailyReport\EventDailyReportService;
use Illuminate\Console\Command;

class SendEventDailyReportsCommand extends Command
{
    protected $signature = 'events:send-daily-reports';

    protected $description = 'Send the daily SMS reports of the events whose local time is reached (once per local day)';

    public function handle(EventDailyReportService $service): int
    {
        $results = $service->sendDue();

        if ($results !== []) {
            $this->info(sprintf('Daily reports sent: %d', count($results)));
        }

        return self::SUCCESS;
    }
}
