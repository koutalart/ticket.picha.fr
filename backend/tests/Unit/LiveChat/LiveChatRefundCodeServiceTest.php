<?php

declare(strict_types=1);

namespace Tests\Unit\LiveChat;

use Carbon\Carbon;
use HiEvents\DomainObjects\LiveChatRefundRequestDomainObject;
use HiEvents\Services\Domain\LiveChat\Refund\LiveChatRefundCodeService;
use Tests\TestCase;

class LiveChatRefundCodeServiceTest extends TestCase
{
    private LiveChatRefundCodeService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = $this->app->make(LiveChatRefundCodeService::class);
    }

    public function test_code_has_six_digits_and_only_its_hash_is_kept(): void
    {
        $code = $this->service->generate();
        $hash = $this->service->hash($code);

        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        $this->assertNotSame($code, $hash);
        $this->assertStringNotContainsString($code, $hash);
        $this->assertTrue($this->service->matches($this->request($hash), $code));
        $this->assertTrue($this->service->matches($this->request($hash), substr($code, 0, 3).' '.substr($code, 3)));
        $this->assertFalse($this->service->matches($this->request($hash), $code === '000000' ? '111111' : '000000'));
        $this->assertFalse($this->service->matches($this->request(null), $code));
    }

    public function test_code_expires_after_fifteen_minutes(): void
    {
        $now = Carbon::parse('2026-10-24 10:00:00', 'UTC');
        $request = $this->request('hash')->setCodeExpiresAt($this->service->expiresAt($now)->toDateTimeString());

        $this->assertFalse($this->service->isExpired($request, $now->copy()->addMinutes(14)));
        $this->assertTrue($this->service->isExpired($request, $now->copy()->addMinutes(15)));
        $this->assertTrue($this->service->isExpired($this->request('hash'), $now));
    }

    public function test_five_attempts_at_most(): void
    {
        $this->assertTrue($this->service->hasAttemptsLeft($this->request('hash')->setAttempts(4)));
        $this->assertFalse($this->service->hasAttemptsLeft($this->request('hash')->setAttempts(5)));
        $this->assertSame(1, $this->service->attemptsLeft(4));
        $this->assertSame(0, $this->service->attemptsLeft(7));
    }

    private function request(?string $hash): LiveChatRefundRequestDomainObject
    {
        return (new LiveChatRefundRequestDomainObject)->setCodeHash($hash)->setAttempts(0);
    }
}
