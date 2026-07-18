<?php

declare(strict_types=1);

namespace Digit\Console\Commands;

use Digit\Devices\Domain\Services\DeviceActivationCodeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DigitScanDeviceActivationCodeCommand extends Command
{
    protected $signature = 'digit:scan:device:activation-code {device_id}';

    protected $description = 'Generate a temporary activation code for a DIGIT scan device (shown once, 15 min TTL, single use).';

    public function __construct(private readonly DeviceActivationCodeService $activationCodeService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $deviceId = (int) $this->argument('device_id');

        $device = DB::table('digit_scan_devices')->where('id', $deviceId)->first();

        if ($device === null) {
            $this->error("Device {$deviceId} not found.");
            return self::FAILURE;
        }

        $code = $this->activationCodeService->generate($deviceId);

        $this->newLine();
        $this->line('<fg=green;options=bold>Activation code generated.</>');
        $this->table(['Champ', 'Valeur'], [
            ['Device ID', $deviceId],
            ['Name', $device->name],
            ['Expires in', '15 minutes'],
        ]);
        $this->newLine();
        $this->warn('Code (a communiquer a l\'agent terrain, usage unique) :');
        $this->line($code);
        $this->newLine();

        return self::SUCCESS;
    }
}
