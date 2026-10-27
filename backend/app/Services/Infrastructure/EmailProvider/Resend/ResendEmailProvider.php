<?php

declare(strict_types=1);

namespace HiEvents\Services\Infrastructure\EmailProvider\Resend;

use HiEvents\DomainObjects\Enums\EmailProviderType;
use HiEvents\DomainObjects\Status\EmailMessageStatus;
use HiEvents\Exceptions\EmailProviderException;
use HiEvents\Services\Infrastructure\EmailProvider\DTO\EmailProviderEventDTO;
use HiEvents\Services\Infrastructure\EmailProvider\DTO\InboundEmailDTO;
use HiEvents\Services\Infrastructure\EmailProvider\EmailAddressParser;
use HiEvents\Services\Infrastructure\EmailProvider\EmailProviderInterface;
use Illuminate\Config\Repository;
use Illuminate\Support\Facades\Http;
use Throwable;

class ResendEmailProvider implements EmailProviderInterface
{
    private const STATUS_EVENTS = [
        'email.sent' => EmailMessageStatus::SENT,
        'email.delivered' => EmailMessageStatus::DELIVERED,
        'email.delivery_delayed' => EmailMessageStatus::DELAYED,
        'email.opened' => EmailMessageStatus::OPENED,
        'email.clicked' => EmailMessageStatus::OPENED,
        'email.bounced' => EmailMessageStatus::BOUNCED,
        'email.complained' => EmailMessageStatus::COMPLAINED,
        'email.failed' => EmailMessageStatus::FAILED,
    ];

    public function __construct(
        private readonly SvixSignatureVerifier $signatureVerifier,
        private readonly EmailAddressParser $addressParser,
        private readonly Repository $config,
    ) {}

    public function type(): EmailProviderType
    {
        return EmailProviderType::RESEND;
    }

    public function verifyWebhook(string $payload, array $headers): void
    {
        $this->signatureVerifier->verify(
            payload: $payload,
            messageId: $headers['svix-id'] ?? null,
            timestamp: $headers['svix-timestamp'] ?? null,
            signatureHeader: $headers['svix-signature'] ?? null,
            secret: $this->config->get('services.resend.webhook_secret'),
        );
    }

    public function parseWebhook(string $payload): ?EmailProviderEventDTO
    {
        $event = json_decode($payload, true);

        if (! is_array($event) || ! is_string($event['type'] ?? null) || ! is_array($event['data'] ?? null)) {
            return null;
        }

        $data = $event['data'];
        $emailId = $data['email_id'] ?? null;

        if (! is_string($emailId) || $emailId === '') {
            return null;
        }

        if ($event['type'] === 'email.received') {
            return new EmailProviderEventDTO(
                kind: EmailProviderEventDTO::KIND_INBOUND,
                providerEmailId: $emailId,
                occurredAt: is_string($event['created_at'] ?? null) ? $event['created_at'] : null,
                recipients: $this->recipients($data),
            );
        }

        $status = self::STATUS_EVENTS[$event['type']] ?? null;

        if ($status === null) {
            return null;
        }

        return new EmailProviderEventDTO(
            kind: EmailProviderEventDTO::KIND_STATUS,
            providerEmailId: $emailId,
            status: $status,
            occurredAt: is_string($event['created_at'] ?? null) ? $event['created_at'] : null,
            errorMessage: $this->errorMessage($data),
        );
    }

    public function fetchInboundEmail(string $providerEmailId): InboundEmailDTO
    {
        $apiKey = $this->config->get('services.resend.api_key');

        if (empty($apiKey)) {
            throw new EmailProviderException('RESEND_API_KEY is not configured');
        }

        try {
            $response = Http::acceptJson()
                ->withToken($apiKey)
                ->timeout($this->config->get('services.resend.timeout_seconds', 15))
                ->get(rtrim((string) $this->config->get('services.resend.base_url'), '/').'/emails/receiving/'.rawurlencode($providerEmailId), [
                    'html_format' => 'cid',
                ]);
        } catch (Throwable $exception) {
            throw new EmailProviderException('Resend is unreachable: '.$exception->getMessage(), 0, $exception);
        }

        if (! $response->successful() || ! is_array($response->json())) {
            throw new EmailProviderException('Resend refused to return the received e-mail (HTTP '.$response->status().')');
        }

        return $this->toInboundEmail($providerEmailId, $response->json());
    }

    /**
     * @param  array<string, mixed>  $email
     */
    public function toInboundEmail(string $providerEmailId, array $email): InboundEmailDTO
    {
        $headers = array_change_key_case(is_array($email['headers'] ?? null) ? $email['headers'] : [], CASE_LOWER);
        $from = $this->addressParser->parseOne((string) ($headers['from'] ?? $email['from'] ?? ''));

        $recipients = $this->recipients($email);

        $references = [];
        foreach (['references', 'in-reply-to'] as $header) {
            if (is_string($headers[$header] ?? null)) {
                preg_match_all('/<[^<>\s]+>/', $headers[$header], $matches);
                $references = [...$references, ...$matches[0]];
            }
        }

        $attachments = [];
        foreach ((array) ($email['attachments'] ?? []) as $attachment) {
            if (is_array($attachment)) {
                $attachments[] = [
                    'filename' => is_string($attachment['filename'] ?? null) ? mb_substr($attachment['filename'], 0, 255) : null,
                    'content_type' => is_string($attachment['content_type'] ?? null) ? mb_substr($attachment['content_type'], 0, 100) : null,
                    'size' => is_numeric($attachment['size'] ?? null) ? (int) $attachment['size'] : null,
                ];
            }
        }

        return new InboundEmailDTO(
            providerEmailId: $providerEmailId,
            fromEmail: $from['email'] ?? '',
            fromName: $from['name'] ?? null,
            recipients: $recipients,
            subject: is_string($email['subject'] ?? null) ? $email['subject'] : null,
            text: is_string($email['text'] ?? null) ? $email['text'] : null,
            html: is_string($email['html'] ?? null) ? $email['html'] : null,
            messageId: is_string($email['message_id'] ?? null) ? $email['message_id'] : null,
            references: array_values(array_reverse(array_unique(array_reverse($references)))),
            attachments: $attachments,
            receivedAt: is_string($email['created_at'] ?? null) ? $email['created_at'] : null,
        );
    }

    /**
     * @param  array<string, mixed>  $email
     * @return array<int, string>
     */
    private function recipients(array $email): array
    {
        $recipients = [];
        foreach (['to', 'cc', 'bcc', 'received_for'] as $field) {
            foreach ((array) ($email[$field] ?? []) as $address) {
                if (is_string($address)) {
                    $recipients = [...$recipients, ...$this->addressParser->parseEmails($address)];
                }
            }
        }

        return array_values(array_unique($recipients));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function errorMessage(array $data): ?string
    {
        foreach (['bounce', 'failed', 'complaint'] as $key) {
            $detail = $data[$key] ?? null;

            if (is_array($detail)) {
                $message = $detail['message'] ?? $detail['reason'] ?? $detail['type'] ?? null;

                if (is_string($message) && $message !== '') {
                    return mb_substr($message, 0, 500);
                }
            }
        }

        return null;
    }
}
