<?php

declare(strict_types=1);

namespace Digit\Console\Commands;

use Digit\Devices\Domain\Exceptions\DeviceTransitionNotAllowedException;
use Digit\Devices\Domain\Services\DeviceLifecycleService;
use Illuminate\Console\Command;

class DigitScanDeviceReactivateCommand extends Command
{
    protected $signature = 'digit:scan:device:reactivate {device_id}';

    protected $description = 'Reactivate a DIGIT scan device (from DISABLED or LOST back to ACTIVE). Not allowed from REVOKED.';

    public function __construct(private readonly DeviceLifecycleService $lifecycleService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $deviceId = (int) $this->argument('device_id');

        try {
            if (!$this->lifecycleService->reactivate($deviceId)) {
                $this->error("Device {$deviceId} not found or already ACTIVE.");
                return self::FAILURE;
            }
        } catch (DeviceTransitionNotAllowedException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $this->info("Device {$deviceId} reactivated.");
        return self::SUCCESS;
    }
}
