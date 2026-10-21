<?php

namespace Tests\Unit\Services\Domain\Sms;

use HiEvents\DomainObjects\SmsCampaignDomainObject;
use HiEvents\DomainObjects\SmsCampaignRecipientDomainObject;
use HiEvents\DomainObjects\Status\SmsCampaignRecipientStatus;
use HiEvents\Repository\Interfaces\SmsCampaignRecipientRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsCampaignRepositoryInterface;
use HiEvents\Services\Domain\Sms\SmsCampaignDeliveryService;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class SmsCampaignDeliveryServiceTest extends TestCase
{
    private SmsCampaignDeliveryService $service;

    private SmsCampaignRecipientRepositoryInterface|MockInterface $recipientRepository;

    private SmsCampaignRepositoryInterface|MockInterface $campaignRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->recipientRepository = Mockery::mock(SmsCampaignRecipientRepositoryInterface::class);
        $this->campaignRepository = Mockery::mock(SmsCampaignRepositoryInterface::class);

        $this->service = new SmsCampaignDeliveryService(
            $this->recipientRepository,
            $this->campaignRepository,
        );
    }

    public function test_status_updates_the_campaign_of_any_organizer_of_the_line(): void
    {
        $this->givenRecipientOfOrganizer(8);

        $this->recipientRepository->shouldReceive('updateFromArray')
            ->once()
            ->withArgs(fn (int $id, array $attributes) => $id === 1 && $attributes['status'] === 'DELIVERED');

        self::assertTrue($this->service->applyProviderStatus([4, 8], 'prov-1', SmsCampaignRecipientStatus::DELIVERED));
        self::assertSame(8, $this->service->findCampaignOrganizerId([4, 8], 'prov-1'));
    }

    public function test_status_never_touches_the_campaign_of_an_organizer_outside_the_line(): void
    {
        $this->givenRecipientOfOrganizer(99);
        $this->recipientRepository->shouldNotReceive('updateFromArray');

        self::assertFalse($this->service->applyProviderStatus([4, 8], 'prov-1', SmsCampaignRecipientStatus::DELIVERED));
        self::assertNull($this->service->findCampaignOrganizerId([4, 8], 'prov-1'));
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

    private function givenRecipientOfOrganizer(int $organizerId): void
    {
        $this->recipientRepository->shouldReceive('findFirstWhere')
            ->with(['provider_message_id' => 'prov-1'])
            ->andReturn($this->recipient('SENT'));
        $this->campaignRepository->shouldReceive('findFirstWhere')
            ->with(['id' => 1])
            ->andReturn((new SmsCampaignDomainObject)->setId(1)->setOrganizerId($organizerId));
    }

    private function recipient(string $status): SmsCampaignRecipientDomainObject
    {
        return (new SmsCampaignRecipientDomainObject)->setId(1)->setSmsCampaignId(1)->setStatus($status);
    }
}
