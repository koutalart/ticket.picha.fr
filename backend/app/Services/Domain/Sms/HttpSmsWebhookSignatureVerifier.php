<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms;

use HiEvents\Exceptions\SmsWebhookUnauthorizedException;

class HttpSmsWebhookSignatureVerifier
{
    private const CLOCK_SKEW_SECONDS = 300;

    /**
     * @throws SmsWebhookUnauthorizedException
     */
    public function verify(?string $bearerToken, string $signingKey, ?int $now = null): void
    {
        if ($bearerToken === null || $bearerToken === '' || $signingKey === '') {
            throw new SmsWebhookUnauthorizedException('Missing webhook token');
        }

        $parts = explode('.', $bearerToken);
        if (count($parts) !== 3) {
            throw new SmsWebhookUnauthorizedException('Malformed webhook token');
        }

        [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;

        $header = json_decode($this->base64UrlDecode($encodedHeader), true);
        if (! is_array($header) || ($header['alg'] ?? null) !== 'HS256') {
            throw new SmsWebhookUnauthorizedException('Unsupported webhook token algorithm');
        }

        $expected = hash_hmac('sha256', $encodedHeader.'.'.$encodedPayload, $signingKey, true);
        if (! hash_equals($expected, $this->base64UrlDecode($encodedSignature))) {
            throw new SmsWebhookUnauthorizedException('Invalid webhook signature');
        }

        $claims = json_decode($this->base64UrlDecode($encodedPayload), true);
        if (! is_array($claims)) {
            throw new SmsWebhookUnauthorizedException('Malformed webhook claims');
        }

        $now ??= time();
        if (isset($claims['exp']) && is_numeric($claims['exp']) && (int) $claims['exp'] + self::CLOCK_SKEW_SECONDS < $now) {
            throw new SmsWebhookUnauthorizedException('Expired webhook token');
        }

        if (isset($claims['nbf']) && is_numeric($claims['nbf']) && (int) $claims['nbf'] - self::CLOCK_SKEW_SECONDS > $now) {
            throw new SmsWebhookUnauthorizedException('Webhook token not yet valid');
        }
    }

    private function base64UrlDecode(string $value): string
    {
        $padded = strtr($value, '-_', '+/');
        $remainder = strlen($padded) % 4;
        if ($remainder > 0) {
            $padded .= str_repeat('=', 4 - $remainder);
        }

        return (string) base64_decode($padded, true);
    }
}
