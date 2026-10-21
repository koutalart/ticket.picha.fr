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
        $sharedLine = $this->sharedLine;
        $line = $sharedLine ?? $settings;

        return [
            'provider' => $line->getProvider(),
            'api_key_configured' => ! empty($line->getApiKey()),
            'from_number' => $line->getFromNumber(),
            'shared_line' => $sharedLine !== null,
            'webhook_url' => $sharedLine === null ? $this->webhookUrl : '',
            'webhook_signing_key' => $sharedLine === null ? $settings->getWebhookSigningKey() : '',
            'last_webhook_received_at' => $line->getLastWebhookReceivedAt(),
            'notify_on_inbound_sms' => $settings->getNotifyOnInboundSms(),
            'phone_status' => $line->getPhoneStatus(),
            'phone_status_changed_at' => $line->getPhoneStatusChangedAt(),
            'assistant_available' => $this->assistantAvailable,
            'assistant_enabled' => $settings->getAssistantEnabled(),
            'assistant_instructions' => $settings->getAssistantInstructions(),
            'history_import' => [
                'status' => $settings->getHistoryImportStatus(),
                'started_at' => $settings->getHistoryImportStartedAt(),
                'finished_at' => $settings->getHistoryImportFinishedAt(),
                'imported_count' => $settings->getHistoryImportCount(),
                'error' => $settings->getHistoryImportError(),
            ],
        ];
    }
}
