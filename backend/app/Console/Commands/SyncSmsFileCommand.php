<?php

namespace HiEvents\Console\Commands;

use HiEvents\Services\Domain\Sms\SmsFileSyncService;
use Illuminate\Console\Command;

class SyncSmsFileCommand extends Command
{
    protected $signature = 'sms-file:sync';

    protected $description = 'Link the SMS of the central SMS queue to their PICHA SMS id and update their status';

    public function handle(SmsFileSyncService $syncService): int
    {
        $this->info(sprintf('SMS checked: %d', $syncService->sync()));

        return self::SUCCESS;
    }
}
