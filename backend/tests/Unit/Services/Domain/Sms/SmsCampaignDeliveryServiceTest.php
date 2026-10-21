<?php

namespace Tests\Unit\Services\Domain\Sms;

use HiEvents\DomainObjects\SmsCampaignRecipientDomainObject;
use HiEvents\DomainObjects\Status\SmsCampaignRecipientStatus;
use HiEvents\Repository\Interfaces\SmsCampaignRecipientRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsCampaignRepositoryInterface;
use HiEvents\Services\Domain\Sms\SmsCampaignDeliveryService;
use Mockery;
use Tests\TestCase;

class SmsCampaignDeliveryServiceTest extends TestCase
{
    private SmsCampaignDeliveryService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new SmsCampaignDeliveryService(
            Mockery::mock(SmsCampaignRecipientRepositoryInterface::class),
            Mockery::mock(SmsCampaignRepositoryInterface::class),
        );
    }

    public function test_status_moves_forward_only(): void
    {
        $sent = $this->recipient('SENT');

        self::assertSame([], $this->service->statusAttributes($sent, SmsCampaignRecipientStatus::PENDING));
        self::assertSame([], $this->service->statusAttributes($sent, SmsCampaignRecipientStatus::SENT));
        self::assertSame([], $this->service->statusAttributes($this->recipient('DELIVERED'), SmsCampaignRecipientStatus::FAILED));

        $attributes = $this->service->statusAttributes($sent, SmsCampaignRecipientStatus::DELIVERED, null, '2026-10-21T12:00:00+03:00');
        self::assertSame('DELIVERED', $attributes['status']);
        self::assertSame('2026-10-21 09:00:00', $attributes['delivered_at']);
    }

    public function test_pending_recipient_can_become_scheduled(): void
    {
        self::assertSame(
            ['status' => 'SCHEDULED'],
            $this->service->statusAttributes($this->recipient('PENDING'), SmsCampaignRecipientStatus::SCHEDULED),
        );
    }

    public function test_failure_keeps_the_reason(): void
    {
        $attributes = $this->service->statusAttributes($this->recipient('SENT'), SmsCampaignRecipientStatus::FAILED, 'Number unreachable');

        self::assertSame('FAILED', $attributes['status']);
        self::assertSame('Number unreachable', $attributes['failure_reason']);
    }

    private function recipient(string $status): SmsCampaignRecipientDomainObject
    {
        return (new SmsCampaignRecipientDomainObject)->setId(1)->setSmsCampaignId(1)->setStatus($status);
    }
}
