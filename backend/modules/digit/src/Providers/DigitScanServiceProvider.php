<?php

declare(strict_types=1);

namespace Digit\Providers;

use Illuminate\Support\ServiceProvider;

class DigitScanServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/digit-scan.php', 'digit-scan');
    }

    public function boot(): void
    {
        // Migrations (scan_logs + unique index) load unconditionally — the
        // DB constraint protects attendee_check_ins regardless of whether
        // the DIGIT module itself is enabled.
        $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');

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
