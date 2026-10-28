<?php

namespace Tests\Unit\Services\Domain\Sms;

use HiEvents\DomainObjects\Enums\SmsFileState;
use HiEvents\Repository\Interfaces\SmsCampaignRecipientRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsMessageRepositoryInterface;
use HiEvents\Services\Domain\Sms\SmsCampaignDeliveryService;
use HiEvents\Services\Domain\Sms\SmsFileStatusService;
use HiEvents\Services\Infrastructure\SmsFile\DTO\SmsFileResultDTO;
use Mockery;
use Tests\TestCase;

class SmsFileStatusServiceTest extends TestCase
{
    private SmsFileStatusService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new SmsFileStatusService(
            Mockery::mock(SmsMessageRepositoryInterface::class),
            Mockery::mock(SmsCampaignRecipientRepositoryInterface::class),
            Mockery::mock(SmsCampaignDeliveryService::class),
        );
    }

    public function test_reads_the_queue_id_of_a_request_id(): void
    {
        $this->assertSame(42, SmsFileStatusService::queueIdFromRequestId('file-42'));
        $this->assertNull(SmsFileStatusService::queueIdFromRequestId('picha-sms-42'));
        $this->assertNull(SmsFileStatusService::queueIdFromRequestId('file-abc'));
        $this->assertNull(SmsFileStatusService::queueIdFromRequestId(null));
    }

    public function test_status_only_moves_forward_and_provider_id_is_kept(): void
    {
        $delivered = new SmsFileResultDTO(id: 7, state: SmsFileState::DELIVERED, providerMessageId: 'p-7');
        $attributes = $this->service->messageAttributes('QUEUED', null, $delivered);
        $this->assertSame('DELIVERED', $attributes['status']);
        $this->assertSame('p-7', $attributes['provider_message_id']);
        $this->assertSame(7, $attributes['queue_message_id']);

        $queued = new SmsFileResultDTO(id: 7, state: SmsFileState::QUEUED, providerMessageId: 'other');
        $attributes = $this->service->messageAttributes('SENT', 'p-7', $queued);
        $this->assertArrayNotHasKey('status', $attributes);
        $this->assertArrayNotHasKey('provider_message_id', $attributes);
    }

    public function test_failure_keeps_the_reason(): void
    {
        $failed = new SmsFileResultDTO(id: 8, state: SmsFileState::FAILED, reason: 'HTTP 400 numero invalide');
        $attributes = $this->service->messageAttributes('QUEUED', null, $failed);

        $this->assertSame('FAILED', $attributes['status']);
        $this->assertSame('HTTP 400 numero invalide', $attributes['error_message']);
    }
}
