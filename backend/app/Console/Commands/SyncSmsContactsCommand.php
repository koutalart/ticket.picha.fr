<?php

namespace HiEvents\Console\Commands;

use HiEvents\Services\Domain\Sms\SmsContactSyncService;
use Illuminate\Console\Command;

class SyncSmsContactsCommand extends Command
{
    protected $signature = 'sms:sync-contacts {--dry-run : Count the changes without writing to httpSMS}';

    protected $description = 'Copy the name and phone number of each organizer customer into the contacts of their httpSMS account';

    public function __construct(
        private readonly SmsContactSyncService $syncService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $result = $this->syncService->syncAll($dryRun);

        $this->info(sprintf(
            ($dryRun ? '[dry-run] ' : '').'httpSMS accounts: %d, created: %d, updated: %d, unchanged: %d, failed: %d',
            $result->accounts,
            $result->created,
            $result->updated,
            $result->unchanged,
            $result->failed,
        ));

        return self::SUCCESS;
    }
}
