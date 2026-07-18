<?php

declare(strict_types=1);

namespace Digit\Providers;

use Digit\Console\Commands\DigitDemoSeedCommand;
use Digit\Console\Commands\DigitScanDeviceCreateCommand;
use Digit\Console\Commands\DigitScanDeviceListCommand;
use Digit\Console\Commands\DigitScanDeviceRevokeCommand;
use Digit\Console\Commands\DigitScanDeviceReactivateCommand;
use Digit\Console\Commands\DigitScanDeviceDisableCommand;
use Digit\Console\Commands\DigitScanDeviceLostCommand;
use Digit\Console\Commands\DigitScanDeviceActivationCodeCommand;
use Digit\Console\Commands\DigitScanDeviceAssignListsCommand;
use Illuminate\Support\ServiceProvider;

class DigitScanServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/digit-scan.php', 'digit-scan');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([
                DigitDemoSeedCommand::class,
                DigitScanDeviceCreateCommand::class,
                DigitScanDeviceListCommand::class,
                DigitScanDeviceRevokeCommand::class,
                DigitScanDeviceReactivateCommand::class,
                DigitScanDeviceDisableCommand::class,
                DigitScanDeviceLostCommand::class,
                DigitScanDeviceActivationCodeCommand::class,
                DigitScanDeviceAssignListsCommand::class,
            ]);
        }

        if (!$this->moduleShouldRun()) {
            return;
        }

        $this->loadRoutesFrom(__DIR__ . '/../../routes/scan.php');
    }

    private function moduleShouldRun(): bool
    {
        return config('digit-scan.module_enabled', false)
            && config('digit-scan.staging_mode', false);
    }
}
