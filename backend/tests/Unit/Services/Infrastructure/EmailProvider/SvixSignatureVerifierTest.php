<?php

namespace Tests\Unit\Services\Infrastructure\EmailProvider;

use HiEvents\Exceptions\EmailWebhookUnauthorizedException;
use HiEvents\Services\Infrastructure\EmailProvider\Resend\SvixSignatureVerifier;
use Tests\TestCase;

class SvixSignatureVerifierTest extends TestCase
{
    private const KEY = 'a-random-test-signing-key';

    private const PAYLOAD = '{"type":"email.delivered","data":{"email_id":"abc"}}';

    public function test_a_correctly_signed_payload_is_accepted(): void
    {
        $now = time();

        (new SvixSignatureVerifier)->verify(
            self::PAYLOAD,
            'msg_1',
            (string) $now,
            'v1,not-this-one '.$this->sign('msg_1', $now, self::PAYLOAD),
            $this->secret(),
            $now,
        );

        $this->addToAssertionCount(1);
    }

    public function test_an_altered_payload_is_rejected(): void
    {
        $now = time();

        $this->expectException(EmailWebhookUnauthorizedException::class);

        (new SvixSignatureVerifier)->verify(
            str_replace('abc', 'xyz', self::PAYLOAD),
            'msg_1',
            (string) $now,
            $this->sign('msg_1', $now, self::PAYLOAD),
            $this->secret(),
            $now,
        );
    }

    public function test_an_old_timestamp_is_rejected_even_when_correctly_signed(): void
    {
        $sentAt = time() - SvixSignatureVerifier::TOLERANCE_SECONDS - 60;

        $this->expectException(EmailWebhookUnauthorizedException::class);

        (new SvixSignatureVerifier)->verify(
            self::PAYLOAD,
            'msg_1',
            (string) $sentAt,
            $this->sign('msg_1', $sentAt, self::PAYLOAD),
            $this->secret(),
            time(),
        );
    }

    public function test_nothing_is_accepted_without_a_configured_secret(): void
    {
        $now = time();

        $this->expectException(EmailWebhookUnauthorizedException::class);

        (new SvixSignatureVerifier)->verify(self::PAYLOAD, 'msg_1', (string) $now, $this->sign('msg_1', $now, self::PAYLOAD), null, $now);
    }

    public function test_missing_headers_are_rejected(): void
    {
        $this->expectException(EmailWebhookUnauthorizedException::class);

        (new SvixSignatureVerifier)->verify(self::PAYLOAD, null, null, null, $this->secret());
    }

    private function secret(): string
    {
        return 'whsec_'.base64_encode(self::KEY);
    }

    private function sign(string $id, int $timestamp, string $payload): string
    {
        return 'v1,'.base64_encode(hash_hmac('sha256', $id.'.'.$timestamp.'.'.$payload, self::KEY, true));
    }
}
