<?php

declare(strict_types=1);

namespace Digit\Bracelets\Domain\Services;

use Digit\Bracelets\Domain\Exceptions\InvalidBraceletPayloadException;

/**
 * Builds and verifies the DIGIT Bracelets QR payload:
 *
 *     DGT1.{code}.{signature}
 *
 * - `DGT1`: protocol version. Only this version is understood today; any
 *   other prefix (or a future DGT2) is rejected here so a version bump can
 *   be introduced later without breaking already-printed DGT1 bracelets -
 *   this service would simply grow a second branch for DGT2 while this one
 *   keeps working unchanged.
 * - `code`: opaque, high-entropy, contains no business data.
 * - `signature`: HMAC-SHA256(code, event_secret), base64url-encoded.
 *
 * The signature is NEVER stored anywhere - it is always derived on demand
 * from the code and the event's secret (see EventSecurityKeyService). This
 * avoids a stored value ever drifting out of sync with what the secret
 * would actually produce.
 */
class BraceletSignatureService
{
    public const VERSION = 'DGT1';

    private const SEPARATOR = '.';

    public function sign(string $code, string $eventSecret): string
    {
        $raw = hash_hmac('sha256', $code, $eventSecret, binary: true);

        return $this->base64UrlEncode($raw);
    }

    public function buildPayload(string $code, string $eventSecret): string
    {
        return self::VERSION . self::SEPARATOR . $code . self::SEPARATOR . $this->sign($code, $eventSecret);
    }

    /**
     * @return array{version: string, code: string, signature: string}
     * @throws InvalidBraceletPayloadException if the payload is malformed
     *         or uses an unsupported version prefix.
     */
    public function parsePayload(string $payload): array
    {
        $parts = explode(self::SEPARATOR, $payload);

        if (count($parts) !== 3) {
            throw new InvalidBraceletPayloadException('Malformed bracelet payload');
        }

        [$version, $code, $signature] = $parts;

        if ($version !== self::VERSION) {
            throw new InvalidBraceletPayloadException("Unsupported payload version: {$version}");
        }

        if ($code === '' || $signature === '') {
            throw new InvalidBraceletPayloadException('Malformed bracelet payload');
        }

        return [
            'version' => $version,
            'code' => $code,
            'signature' => $signature,
        ];
    }

    /**
     * Verifies a full "DGT1.code.signature" payload against an event
     * secret. Recomputes the signature and compares in constant time
     * (hash_equals) - never a plain `===` comparison, to avoid a timing
     * side-channel. Throws rather than returning false so callers cannot
     * accidentally ignore the return value and fall through to a DB
     * lookup on a forged payload.
     *
     * @return string the verified bracelet `code`, for convenience
     * @throws InvalidBraceletPayloadException
     */
    public function verifyAndExtractCode(string $payload, string $eventSecret): string
    {
        $parsed = $this->parsePayload($payload);

        $expectedSignature = $this->sign($parsed['code'], $eventSecret);

        if (!hash_equals($expectedSignature, $parsed['signature'])) {
            throw new InvalidBraceletPayloadException('Signature mismatch');
        }

        return $parsed['code'];
    }

    private function base64UrlEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
