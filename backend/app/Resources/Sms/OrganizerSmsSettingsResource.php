<?php

declare(strict_types=1);

namespace HiEvents\Resources\Sms;

use HiEvents\Resources\BaseResource;
use HiEvents\Services\Application\Handlers\Sms\DTO\OrganizerSmsSettingsViewDTO;

/**
 * @mixin OrganizerSmsSettingsViewDTO
 */
class OrganizerSmsSettingsResource extends BaseResource
{
    public function toArray($request): array
    {
        $settings = $this->settings;

        return [
            'provider' => $settings->getProvider(),
            'api_key_configured' => ! empty($settings->getApiKey()),
            'from_number' => $settings->getFromNumber(),
            'webhook_url' => $this->webhookUrl,
            'webhook_signing_key' => $settings->getWebhookSigningKey(),
            'last_webhook_received_at' => $settings->getLastWebhookReceivedAt(),
            'assistant_available' => $this->assistantAvailable,
            'assistant_enabled' => $settings->getAssistantEnabled(),
            'assistant_instructions' => $settings->getAssistantInstructions(),
        ];
    }
}
