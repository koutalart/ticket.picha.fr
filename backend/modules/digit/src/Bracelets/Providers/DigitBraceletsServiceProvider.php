<?php

declare(strict_types=1);

namespace Digit\Bracelets\Providers;

use Digit\Bracelets\Console\Commands\DigitBraceletsExportCommand;
use Digit\Bracelets\Console\Commands\DigitBraceletsGenerateCommand;
use Illuminate\Support\ServiceProvider;

/**
 * Deliberately separate from DigitScanServiceProvider - keeps Scan and
 * Bracelets fully independent modules, consistent with the modular
 * convention already established under backend/modules/digit/.
 */
class DigitBraceletsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../../config/digit-bracelets.php', 'digit-bracelets');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([
                DigitBraceletsGenerateCommand::class,
                DigitBraceletsExportCommand::class,
            ]);
        }

        if (!$this->moduleShouldRun()) {
            return;
        }

        $this->loadRoutesFrom(__DIR__ . '/../../../routes/bracelets.php');
    }

    private function moduleShouldRun(): bool
    {
        return config('digit-bracelets.module_enabled', false);
    }
}
