<?php

declare(strict_types=1);

namespace HiEvents\Services\Infrastructure\EmailProvider;

use HiEvents\DomainObjects\Enums\EmailProviderType;
use HiEvents\Exceptions\EmailProviderException;
use HiEvents\Exceptions\EmailWebhookUnauthorizedException;
use HiEvents\Services\Infrastructure\EmailProvider\DTO\EmailProviderEventDTO;
use HiEvents\Services\Infrastructure\EmailProvider\DTO\InboundEmailDTO;

/**
 * Outbound e-mails leave through the Laravel mailer (SMTP of the provider), so this contract only covers what the
 * mailer cannot do: authenticating the provider webhooks, reading delivery events and fetching received e-mails.
 */
interface EmailProviderInterface
{
    public function type(): EmailProviderType;

    /**
     * @param  array<string, string|null>  $headers  lower-cased request headers
     *
     * @throws EmailWebhookUnauthorizedException
     */
    public function verifyWebhook(string $payload, array $headers): void;

    /**
     * Null when the event is not one the inbox follows.
     */
    public function parseWebhook(string $payload): ?EmailProviderEventDTO;

    /**
     * @throws EmailProviderException
     */
    public function fetchInboundEmail(string $providerEmailId): InboundEmailDTO;
}
