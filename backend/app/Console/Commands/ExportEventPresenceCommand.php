<?php

namespace HiEvents\Console\Commands;

use HiEvents\Services\Domain\EventPresence\EventPresenceCsvService;
use Illuminate\Console\Command;

class ExportEventPresenceCommand extends Command
{
    protected $signature = 'presence:export {event_id} {--path=}';

    protected $description = 'Write the attendees recorded by the staff (page /merci/presence) to a CSV file';

    public function handle(EventPresenceCsvService $csvService): int
    {
        $eventId = (int) $this->argument('event_id');
        $path = $this->option('path') ?: storage_path(sprintf('app/exports/presence-event-%d.csv', $eventId));

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }

        file_put_contents($path, $csvService->build($eventId));
        $this->info($path);

        return self::SUCCESS;
    }
}
