<?php

namespace HiEvents\Console\Commands;

use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Services\Domain\Sms\DTO\SmsCampaignImportDTO;
use HiEvents\Services\Domain\Sms\DTO\SmsCampaignImportRecipientDTO;
use HiEvents\Services\Domain\Sms\DTO\SmsCampaignRecipientMessageDTO;
use HiEvents\Services\Domain\Sms\SmsCampaignImportService;
use Illuminate\Console\Command;

class ImportSmsCampaignCommand extends Command
{
    protected $signature = 'sms-campaigns:import
        {organizer_id : Organizer that sent the campaign}
        {file : JSON file with name, event_id, message and recipients}
        {--retire-qr-campaign= : QR campaign to remove from the QR codes tab once its links are moved}
        {--link-base= : Site address used for {lien_personnel} (default: organizer custom domain, then event page)}
        {--messages= : JSON file to write with the message of each recipient, {lien_personnel} replaced by their ?c= link}';

    protected $description = 'Create or refresh an SMS campaign and attach its tracked links (re-runnable to refresh delivery statuses)';

    public function handle(SmsCampaignImportService $importService): int
    {
        $payload = json_decode((string) @file_get_contents((string) $this->argument('file')), true);

        if (! is_array($payload) || ! is_string($payload['name'] ?? null) || ! is_array($payload['recipients'] ?? null)) {
            $this->error('Invalid file: expected {"name": "...", "recipients": [...]}');

            return self::FAILURE;
        }

        $recipients = [];
        foreach ($payload['recipients'] as $row) {
            if (! is_array($row) || ! isset($row['phone'])) {
                continue;
            }

            $recipients[] = new SmsCampaignImportRecipientDTO(
                phone: (string) $row['phone'],
                contactName: $this->stringOrNull($row['name'] ?? null),
                shortId: $this->stringOrNull($row['short_id'] ?? null),
                providerMessageId: $this->stringOrNull($row['provider_message_id'] ?? null),
                status: $this->stringOrNull($row['status'] ?? null),
                failureReason: $this->stringOrNull($row['failure_reason'] ?? null),
                scheduledAt: $this->stringOrNull($row['scheduled_at'] ?? null),
            );
        }

        $retire = $this->option('retire-qr-campaign');

        try {
            $result = $importService->import(new SmsCampaignImportDTO(
                organizerId: (int) $this->argument('organizer_id'),
                name: mb_substr(trim($payload['name']), 0, 120),
                recipients: $recipients,
                eventId: isset($payload['event_id']) ? (int) $payload['event_id'] : null,
                message: $this->stringOrNull($payload['message'] ?? null),
                retireQrCampaignId: $retire !== null ? (int) $retire : null,
                linkBaseUrl: $this->stringOrNull($this->option('link-base')),
            ));
        } catch (ResourceNotFoundException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'SMS campaign %d: %d created, %d updated, %d tracked links attached%s.',
            $result->campaignId,
            $result->created,
            $result->updated,
            $result->linkedQrLinks,
            $result->qrCampaignRetired ? ', QR campaign retired' : '',
        ));

        if ($result->contactsCreated > 0) {
            $this->info(sprintf('%d contacts created from the recipients.', $result->contactsCreated));
        }

        $messagesFile = $this->stringOrNull($this->option('messages'));
        if ($messagesFile !== null) {
            file_put_contents($messagesFile, json_encode(
                array_map(fn (SmsCampaignRecipientMessageDTO $message) => [
                    'phone' => $message->phone,
                    'name' => $message->contactName,
                    'link' => $message->link,
                    'message' => $message->message,
                ], $result->messages),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));
            $this->info(sprintf('%d personal messages written to %s.', count($result->messages), $messagesFile));
        }

        foreach ($result->warnings as $line) {
            $this->warn('Warning: '.$line);
        }

        return self::SUCCESS;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }
}
