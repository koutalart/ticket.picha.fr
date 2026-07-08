<?php

declare(strict_types=1);

namespace Digit\Tests\Bracelets\Unit;

use Digit\Bracelets\Domain\Exceptions\InvalidBraceletPayloadException;
use Digit\Bracelets\Domain\Services\BraceletSignatureService;
use PHPUnit\Framework\TestCase;

/**
 * Zero framework/DB dependency - BraceletSignatureService only uses PHP's
 * own hash_hmac/hash_equals, so this runs anywhere with `php` installed,
 * no Laravel bootstrap, no database.
 *
 * Run: ./vendor/bin/phpunit modules/digit/tests/Bracelets/Unit/BraceletSignatureServiceTest.php
 */
class BraceletSignatureServiceTest extends TestCase
{
    private BraceletSignatureService $service;

    protected function setUp(): void
    {
        $this->service = new BraceletSignatureService();
    }

    public function testBuildPayloadHasExpectedVersionedShape(): void
    {
        $payload = $this->service->buildPayload('BR7K9X2M4QQZ8J3F', 'event-secret-key');

        $this->assertStringStartsWith('DGT1.BR7K9X2M4QQZ8J3F.', $payload);
        $this->assertSame(3, count(explode('.', $payload)));
    }

    public function testValidPayloadIsAccepted(): void
    {
        $secret = 'super-secret-event-key';
        $payload = $this->service->buildPayload('BR7K9X2M4QQZ8J3F', $secret);

        $code = $this->service->verifyAndExtractCode($payload, $secret);

        $this->assertSame('BR7K9X2M4QQZ8J3F', $code);
    }

    public function testTamperedCodeIsRejected(): void
    {
        $secret = 'super-secret-event-key';
        $payload = $this->service->buildPayload('BR7K9X2M4QQZ8J3F', $secret);

        // Attacker flips one character of the code without recomputing the signature.
        $tampered = str_replace('BR7K9X2M4QQZ8J3F', 'BR7K9X2M4QQZ8J3G', $payload);

        $this->expectException(InvalidBraceletPayloadException::class);
        $this->service->verifyAndExtractCode($tampered, $secret);
    }

    public function testTamperedSignatureIsRejected(): void
    {
        $secret = 'super-secret-event-key';
        $payload = $this->service->buildPayload('BR7K9X2M4QQZ8J3F', $secret);
        $parts = explode('.', $payload);
        $tampered = $parts[0] . '.' . $parts[1] . '.' . strrev($parts[2]);

        $this->expectException(InvalidBraceletPayloadException::class);
        $this->service->verifyAndExtractCode($tampered, $secret);
    }

    public function testWrongEventSecretIsRejected(): void
    {
        $payload = $this->service->buildPayload('BR7K9X2M4QQZ8J3F', 'secret-for-event-A');

        $this->expectException(InvalidBraceletPayloadException::class);
        $this->service->verifyAndExtractCode($payload, 'secret-for-event-B');
    }

    public function testUnsupportedVersionPrefixIsRejected(): void
    {
        $secret = 'super-secret-event-key';
        $signature = $this->service->sign('BR7K9X2M4QQZ8J3F', $secret);

        $this->expectException(InvalidBraceletPayloadException::class);
        $this->service->verifyAndExtractCode('DGT2.BR7K9X2M4QQZ8J3F.' . $signature, $secret);
    }

    public function testMalformedPayloadShapeIsRejected(): void
    {
        $this->expectException(InvalidBraceletPayloadException::class);
        $this->service->verifyAndExtractCode('not-a-valid-payload', 'any-secret');
    }

    public function testEmptyCodeOrSignatureIsRejected(): void
    {
        $this->expectException(InvalidBraceletPayloadException::class);
        $this->service->verifyAndExtractCode('DGT1..signature', 'any-secret');
    }
}
