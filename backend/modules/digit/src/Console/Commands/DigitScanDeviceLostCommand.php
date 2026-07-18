<?php

declare(strict_types=1);

namespace Digit\Console\Commands;

use Digit\Devices\Domain\Exceptions\DeviceTransitionNotAllowedException;
use Digit\Devices\Domain\Services\DeviceLifecycleService;
use Illuminate\Console\Command;

class DigitScanDeviceLostCommand extends Command
{
    protected $signature = 'digit:scan:device:lost {device_id}';

    protected $description = 'Mark a DIGIT scan device as LOST (reactivatable if found later, unlike revoke).';

    public function __construct(private readonly DeviceLifecycleService $lifecycleService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $deviceId = (int) $this->argument('device_id');

        try {
            if (!$this->lifecycleService->markLost($deviceId)) {
                $this->error("Device {$deviceId} not found or already LOST.");
                return self::FAILURE;
            }
        } catch (DeviceTransitionNotAllowedException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $this->info("Device {$deviceId} marked as LOST.");
        return self::SUCCESS;
    }
}
