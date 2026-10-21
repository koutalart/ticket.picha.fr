<?php

declare(strict_types=1);

namespace HiEvents\Jobs\Sms;

use HiEvents\Services\Domain\Sms\SmsHistoryImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ImportSmsHistoryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 55;

    public function __construct(
        public readonly int $settingsId,
        public readonly int $skip = 0,
    ) {}

    /**
     * @throws Throwable
     */
    public function handle(SmsHistoryImportService $service): void
    {
        $nextSkip = $service->importPage($this->settingsId, $this->skip);

        if ($nextSkip !== null) {
            self::dispatch($this->settingsId, $nextSkip);
        }
    }

    public function failed(Throwable $exception): void
    {
        app(SmsHistoryImportService::class)->markFailed($this->settingsId, $exception);
    }
}
