<?php

namespace Tests\Unit\Services\Domain\Sms;

use HiEvents\Exceptions\SmsWebhookUnauthorizedException;
use HiEvents\Services\Domain\Sms\HttpSmsWebhookSignatureVerifier;
use Tests\TestCase;

class HttpSmsWebhookSignatureVerifierTest extends TestCase
{
    private const KEY = 'signing-key-for-tests';

    private HttpSmsWebhookSignatureVerifier $verifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->verifier = new HttpSmsWebhookSignatureVerifier;
    }

    public function test_accepts_valid_token(): void
    {
        $this->verifier->verify($this->token(['exp' => time() + 60]), self::KEY);
        $this->addToAssertionCount(1);
    }

    public function test_rejects_wrong_key(): void
    {
        $this->expectException(SmsWebhookUnauthorizedException::class);
        $this->verifier->verify($this->token(['exp' => time() + 60]), 'another-key');
    }

    public function test_rejects_missing_token(): void
    {
        $this->expectException(SmsWebhookUnauthorizedException::class);
        $this->verifier->verify(null, self::KEY);
    }

    public function test_rejects_expired_token(): void
    {
        $this->expectException(SmsWebhookUnauthorizedException::class);
        $this->verifier->verify($this->token(['exp' => time() - 3600]), self::KEY);
    }

    public function test_rejects_none_algorithm(): void
    {
        $header = $this->encode(json_encode(['alg' => 'none', 'typ' => 'JWT']));
        $payload = $this->encode(json_encode(['exp' => time() + 60]));

        $this->expectException(SmsWebhookUnauthorizedException::class);
        $this->verifier->verify($header.'.'.$payload.'.', self::KEY);
    }

    public function test_rejects_tampered_payload(): void
    {
        [$header, , $signature] = explode('.', $this->token(['exp' => time() + 60]));
        $payload = $this->encode(json_encode(['exp' => time() + 99999]));

        $this->expectException(SmsWebhookUnauthorizedException::class);
        $this->verifier->verify($header.'.'.$payload.'.'.$signature, self::KEY);
    }

    private function token(array $claims): string
    {
        $header = $this->encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $payload = $this->encode(json_encode($claims));
        $signature = $this->encode(hash_hmac('sha256', $header.'.'.$payload, self::KEY, true));

        return $header.'.'.$payload.'.'.$signature;
    }

    private function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
