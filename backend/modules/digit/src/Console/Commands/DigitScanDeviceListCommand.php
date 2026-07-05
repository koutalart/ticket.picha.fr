<?php

declare(strict_types=1);

namespace Digit\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DigitScanDeviceListCommand extends Command
{
    protected $signature = 'digit:scan:device:list {--account-id= : Filter by account ID}';

    protected $description = 'List DIGIT scan devices (tokens are never stored in plaintext, so they are never shown here).';

    public function handle(): int
    {
        $query = DB::table('digit_scan_devices')->orderBy('id');

        if ($accountId = $this->option('account-id')) {
            $query->where('account_id', (int) $accountId);
        }

        $devices = $query->get();

        if ($devices->isEmpty()) {
            $this->info('No devices found.');
            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Name', 'Account', 'Event', 'Check-in list', 'Last used', 'Revoked'],
            $devices->map(fn ($d) => [
                $d->id,
                $d->name,
                $d->account_id,
                $d->event_id ?? '-',
                $d->check_in_list_id ?? '-',
                $d->last_used_at ?? 'never',
                $d->revoked_at ?? '-',
            ])->toArray()
        );

        return self::SUCCESS;
    }
}
