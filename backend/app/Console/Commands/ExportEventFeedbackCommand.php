<?php

namespace HiEvents\Console\Commands;

use HiEvents\Services\Domain\EventFeedback\EventFeedbackCsvService;
use Illuminate\Console\Command;

class ExportEventFeedbackCommand extends Command
{
    protected $signature = 'feedback:export {event_id} {--path=}';

    protected $description = 'Write the feedback of an event (page /merci) to a CSV file';

    public function handle(EventFeedbackCsvService $csvService): int
    {
        $eventId = (int) $this->argument('event_id');
        $path = $this->option('path') ?: storage_path(sprintf('app/exports/feedback-event-%d.csv', $eventId));

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }

        file_put_contents($path, $csvService->build($eventId));
        $this->info($path);

        return self::SUCCESS;
    }
}
