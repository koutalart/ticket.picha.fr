<?php

declare(strict_types=1);

namespace Digit\Console\Commands;

use Digit\Devices\Domain\Exceptions\DeviceTransitionNotAllowedException;
use Digit\Devices\Domain\Services\DeviceLifecycleService;
use Illuminate\Console\Command;

class DigitScanDeviceDisableCommand extends Command
{
    protected $signature = 'digit:scan:device:disable {device_id}';

    protected $description = 'Disable a DIGIT scan device temporarily (reactivatable later, unlike revoke).';

    public function __construct(private readonly DeviceLifecycleService $lifecycleService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $deviceId = (int) $this->argument('device_id');

        try {
            if (!$this->lifecycleService->disable($deviceId)) {
                $this->error("Device {$deviceId} not found or already DISABLED.");
                return self::FAILURE;
            }
        } catch (DeviceTransitionNotAllowedException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $this->info("Device {$deviceId} disabled.");
        return self::SUCCESS;
    }
}
