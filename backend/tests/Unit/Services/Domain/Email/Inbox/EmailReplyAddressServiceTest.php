<?php

namespace Tests\Unit\Services\Domain\Email\Inbox;

use HiEvents\Services\Domain\Email\Inbox\EmailReplyAddressService;
use Tests\TestCase;

class EmailReplyAddressServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.email_inbox.reply_domain' => 'in.picha.fr', 'services.email_inbox.reply_local_prefix' => 'r']);
    }

    public function test_the_address_of_a_thread_resolves_back_to_it(): void
    {
        $service = $this->service();
        $address = $service->addressFor(42, 7);

        $this->assertMatchesRegularExpression('/^r-42-[0-9a-f]{20}@in\.picha\.fr$/', $address);

        $candidates = $service->extractCandidates(['someone@else.test', mb_strtoupper($address)]);

        $this->assertCount(1, $candidates);
        $this->assertSame(42, $candidates[0]['thread_id']);
        $this->assertTrue($service->isValid(42, 7, $candidates[0]['signature']));
    }

    public function test_a_signature_does_not_open_the_thread_of_another_organizer_or_another_thread(): void
    {
        $service = $this->service();
        $candidate = $service->extractCandidates([$service->addressFor(42, 7)])[0];

        $this->assertFalse($service->isValid(42, 14, $candidate['signature']));
        $this->assertFalse($service->isValid(43, 7, $candidate['signature']));
    }

    public function test_addresses_of_other_domains_are_not_candidates(): void
    {
        $address = $this->service()->addressFor(42, 7);

        $this->assertSame([], $this->service()->extractCandidates([str_replace('in.picha.fr', 'evil.test', $address)]));
    }

    public function test_reply_tracking_is_off_without_a_reply_domain(): void
    {
        config(['services.email_inbox.reply_domain' => null]);

        $this->assertFalse($this->service()->isEnabled());
        $this->assertNull($this->service()->addressFor(42, 7));
        $this->assertSame([], $this->service()->extractCandidates(['r-42-0123456789abcdef0123@in.picha.fr']));
    }

    private function service(): EmailReplyAddressService
    {
        return $this->app->make(EmailReplyAddressService::class);
    }
}
