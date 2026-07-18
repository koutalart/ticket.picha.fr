<?php

declare(strict_types=1);

namespace Digit\Console\Commands;

use Digit\Devices\Domain\Services\DeviceLifecycleService;
use Illuminate\Console\Command;

class DigitScanDeviceRevokeCommand extends Command
{
    protected $signature = 'digit:scan:device:revoke {device_id : The Device ID to revoke}';

    protected $description = 'Revoke a DIGIT scan device token immediately.';

    public function __construct(private readonly DeviceLifecycleService $lifecycleService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $deviceId = (int) $this->argument('device_id');

        if (!$this->lifecycleService->revoke($deviceId)) {
            $this->error("Device {$deviceId} not found or already revoked.");
            return self::FAILURE;
        }

        $this->info("Device {$deviceId} revoked.");
        return self::SUCCESS;
    }
}
