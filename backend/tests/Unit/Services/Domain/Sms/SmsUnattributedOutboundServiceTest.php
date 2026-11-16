<?php

namespace Tests\Unit\Services\Domain\Sms;

use HiEvents\DomainObjects\OrganizerSmsSettingDomainObject;
use HiEvents\DomainObjects\SmsUnattributedOutboundMessageDomainObject;
use HiEvents\DomainObjects\Status\SmsMessageStatus;
use HiEvents\Repository\Interfaces\SmsUnattributedOutboundMessageRepositoryInterface;
use HiEvents\Services\Domain\Sms\SmsUnattributedOutboundService;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class SmsUnattributedOutboundServiceTest extends TestCase
{
    private SmsUnattributedOutboundMessageRepositoryInterface|MockInterface $repository;

    private SmsUnattributedOutboundService $service;

    private OrganizerSmsSettingDomainObject $line;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = Mockery::mock(SmsUnattributedOutboundMessageRepositoryInterface::class);
        $this->service = new SmsUnattributedOutboundService($this->repository);
        $this->line = (new OrganizerSmsSettingDomainObject)->setId(1)->setOrganizerId(4);
    }

    public function test_sms_sent_for_no_organizer_is_kept_for_the_platform(): void
    {
        $this->repository->shouldReceive('findFirstWhere')->andReturnNull();
        $this->repository->shouldReceive('create')->once()->with([
            'line_settings_id' => 1,
            'phone' => '+262639111111',
            'content' => 'Votre code : 1234',
            'provider_message_id' => 'prov-1',
            'request_id' => 'file-12',
            'status' => SmsMessageStatus::SENT->name,
            'error_message' => null,
        ])->andReturn(new SmsUnattributedOutboundMessageDomainObject);

        $this->service->record($this->line, 'prov-1', '+262639111111', 'Votre code : 1234', 'file-12', SmsMessageStatus::SENT, null);
    }

    public function test_later_events_update_the_status_without_going_back(): void
    {
        $sent = (new SmsUnattributedOutboundMessageDomainObject)->setId(5)->setStatus(SmsMessageStatus::SENT->name);
        $delivered = (new SmsUnattributedOutboundMessageDomainObject)->setId(5)->setStatus(SmsMessageStatus::DELIVERED->name);
        $this->repository->shouldReceive('findFirstWhere')->andReturn($sent, $delivered);
        $this->repository->shouldNotReceive('create');
        $this->repository->shouldReceive('updateFromArray')->once()
            ->with(5, ['status' => SmsMessageStatus::DELIVERED->name, 'error_message' => null])
            ->andReturn($delivered);

        $this->service->record($this->line, 'prov-1', '+262639111111', null, null, SmsMessageStatus::DELIVERED, null);
        $this->service->record($this->line, 'prov-1', '+262639111111', null, null, SmsMessageStatus::SENT, null);
    }
}
