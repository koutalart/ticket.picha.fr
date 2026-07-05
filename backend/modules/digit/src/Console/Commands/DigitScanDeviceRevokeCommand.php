<?php

declare(strict_types=1);

namespace Digit\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DigitScanDeviceRevokeCommand extends Command
{
    protected $signature = 'digit:scan:device:revoke {device_id : The Device ID to revoke}';

    protected $description = 'Revoke a DIGIT scan device token immediately.';

    public function handle(): int
    {
        $deviceId = (int) $this->argument('device_id');

        $updated = DB::table('digit_scan_devices')
            ->where('id', $deviceId)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'updated_at' => now()]);

        if ($updated === 0) {
            $this->error("Device {$deviceId} not found or already revoked.");
            return self::FAILURE;
        }

        $this->info("Device {$deviceId} revoked.");
        return self::SUCCESS;
    }
}
