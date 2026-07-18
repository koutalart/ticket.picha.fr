<?php

declare(strict_types=1);

namespace Digit\Console\Commands;

use Digit\Devices\Domain\Services\DeviceCheckInListAssignmentService;
use Illuminate\Console\Command;

class DigitScanDeviceAssignListsCommand extends Command
{
    protected $signature = 'digit:scan:device:assign-lists
        {device_id}
        {check_in_list_ids?* : Check-in list IDs (space-separated)}
        {--replace : Replace the entire assignment instead of adding to it}
        {--remove= : Remove a single check-in list ID instead of adding}';

    protected $description = 'Manage the check-in lists a DIGIT scan device is authorized for (multi-list support).';

    public function __construct(private readonly DeviceCheckInListAssignmentService $assignmentService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $deviceId = (int) $this->argument('device_id');
        $removeId = $this->option('remove');
        $replace = (bool) $this->option('replace');
        $checkInListIds = array_map('intval', $this->argument('check_in_list_ids'));

        if ($removeId !== null) {
            $this->assignmentService->remove($deviceId, (int) $removeId);
            $this->info("Check-in list {$removeId} removed from device {$deviceId}.");
            $this->displayCurrentLists($deviceId);
            return self::SUCCESS;
        }

        if (empty($checkInListIds)) {
            $this->error('Provide at least one check-in list ID, or use --remove=ID.');
            return self::FAILURE;
        }

        if ($replace) {
            $this->assignmentService->replace($deviceId, $checkInListIds);
            $this->info("Device {$deviceId} check-in lists replaced.");
        } else {
            $this->assignmentService->assign($deviceId, $checkInListIds);
            $this->info("Device {$deviceId} check-in lists updated (added).");
        }

        $this->displayCurrentLists($deviceId);
        return self::SUCCESS;
    }

    private function displayCurrentLists(int $deviceId): void
    {
        $lists = $this->assignmentService->list($deviceId);
        $this->line('Current check-in lists: ' . ($lists === [] ? '(none)' : implode(', ', $lists)));
    }
}
