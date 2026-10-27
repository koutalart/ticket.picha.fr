<?php

declare(strict_types=1);

namespace HiEvents\Services\Infrastructure\EmailProvider\Resend;

use HiEvents\Exceptions\EmailWebhookUnauthorizedException;

/**
 * Resend signs its webhooks with Svix: HMAC-SHA256 of "{svix-id}.{svix-timestamp}.{raw body}" with the base64
 * secret that follows "whsec_", sent base64 encoded as one or more "v1,{signature}" in the svix-signature header.
 */
class SvixSignatureVerifier
{
    public const TOLERANCE_SECONDS = 300;

    /**
     * @throws EmailWebhookUnauthorizedException
     */
    public function verify(
        string $payload,
        ?string $messageId,
        ?string $timestamp,
        ?string $signatureHeader,
        ?string $secret,
        ?int $now = null,
    ): void {
        if ($secret === null || $secret === '') {
            throw new EmailWebhookUnauthorizedException('Webhook secret is not configured');
        }

        if ($messageId === null || $messageId === '' || $timestamp === null || $signatureHeader === null || $signatureHeader === '') {
            throw new EmailWebhookUnauthorizedException('Missing webhook signature headers');
        }

        if (! ctype_digit($timestamp) || abs(($now ?? time()) - (int) $timestamp) > self::TOLERANCE_SECONDS) {
            throw new EmailWebhookUnauthorizedException('Webhook timestamp outside the tolerance');
        }

        $key = base64_decode(str_starts_with($secret, 'whsec_') ? substr($secret, 6) : $secret, true);
        if ($key === false || $key === '') {
            throw new EmailWebhookUnauthorizedException('Malformed webhook secret');
        }

        $expected = base64_encode(hash_hmac('sha256', $messageId.'.'.$timestamp.'.'.$payload, $key, true));

        foreach (preg_split('/\s+/', trim($signatureHeader)) ?: [] as $candidate) {
            [$version, $signature] = array_pad(explode(',', $candidate, 2), 2, '');

            if ($version === 'v1' && hash_equals($expected, $signature)) {
                return;
            }
        }

        throw new EmailWebhookUnauthorizedException('Invalid webhook signature');
    }
}
