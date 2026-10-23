<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Sms;

use HiEvents\Services\Application\Handlers\Sms\DTO\OrganizerSmsSettingsViewDTO;
use HiEvents\Services\Domain\Sms\SmsAssistantService;
use HiEvents\Services\Domain\Sms\SmsSettingsService;

class GetOrganizerSmsSettingsHandler
{
    public function __construct(
        private readonly SmsSettingsService $settingsService,
        private readonly SmsAssistantService $assistantService,
    ) {}

    public function handle(int $accountId, int $organizerId): OrganizerSmsSettingsViewDTO
    {
        $settings = $this->settingsService->getOrCreate($accountId, $organizerId);

        return new OrganizerSmsSettingsViewDTO(
            settings: $settings,
            webhookUrl: $this->settingsService->getWebhookUrl($settings),
            assistantAvailable: $this->assistantService->isAvailable(),
            sharedLine: $this->settingsService->findSharedLine($settings),
        );
    }
}
