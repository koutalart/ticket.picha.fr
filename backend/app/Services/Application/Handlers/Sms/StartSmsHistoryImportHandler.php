<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Sms;

use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Exceptions\SmsGatewayNotConfiguredException;
use HiEvents\Jobs\Sms\ImportSmsHistoryJob;
use HiEvents\Services\Application\Handlers\Sms\DTO\OrganizerSmsSettingsViewDTO;
use HiEvents\Services\Domain\Sms\SmsHistoryImportService;
use HiEvents\Services\Domain\Sms\SmsSettingsService;

class StartSmsHistoryImportHandler
{
    public function __construct(
        private readonly SmsSettingsService $settingsService,
        private readonly SmsHistoryImportService $historyImportService,
    ) {}

    /**
     * @throws SmsGatewayNotConfiguredException
     * @throws ResourceConflictException
     */
    public function handle(int $accountId, int $organizerId): OrganizerSmsSettingsViewDTO
    {
        $settings = $this->historyImportService->markStarted(
            $this->settingsService->getOrCreate($accountId, $organizerId)
        );

        ImportSmsHistoryJob::dispatch($settings->getId());

        return new OrganizerSmsSettingsViewDTO(
            settings: $settings,
            webhookUrl: $this->settingsService->getWebhookUrl($settings),
        );
    }
}
