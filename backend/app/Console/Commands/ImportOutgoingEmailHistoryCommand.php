<?php

declare(strict_types=1);

namespace HiEvents\Console\Commands;

use HiEvents\Services\Domain\Email\Inbox\OutgoingMessageImportService;
use Illuminate\Console\Command;

class ImportOutgoingEmailHistoryCommand extends Command
{
    protected $signature = 'email-inbox:import-history
        {--organizer= : Only import the messages of this organizer id}
        {--dry-run : Count the messages without importing them}';

    protected $description = 'Import the event messages sent before the e-mail log existed into the Messages > E-mails threads (safe to run again)';

    public function handle(OutgoingMessageImportService $importService): int
    {
        $organizerId = $this->option('organizer');
        $result = $importService->import(
            organizerId: $organizerId !== null ? (int) $organizerId : null,
            dryRun: (bool) $this->option('dry-run'),
        );

        $this->info(sprintf(
            '%s: %d, skipped: %d (messages sent before %s)',
            $this->option('dry-run') ? 'To import' : 'Imported',
            $result->imported,
            $result->skipped,
            $result->sentBefore,
        ));

        return self::SUCCESS;
    }
}
