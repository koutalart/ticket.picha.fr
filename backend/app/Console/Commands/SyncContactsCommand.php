<?php

namespace HiEvents\Console\Commands;

use HiEvents\Services\Domain\Contact\ContactSyncService;
use Illuminate\Console\Command;

class SyncContactsCommand extends Command
{
    protected $signature = 'contacts:sync {--organizer= : Only sync this organizer id}';

    protected $description = 'Rebuild organizer contacts from completed orders and attendees';

    public function handle(ContactSyncService $contactSyncService): int
    {
        $organizerId = $this->option('organizer');

        $results = $organizerId !== null
            ? [(int) $organizerId => $contactSyncService->syncOrganizer((int) $organizerId)]
            : $contactSyncService->syncAllOrganizers();

        foreach ($results as $id => $count) {
            $this->line("Organizer {$id}: {$count} contacts synced");
        }

        return self::SUCCESS;
    }
}
