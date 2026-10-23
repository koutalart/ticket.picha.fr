<?php

namespace Tests\Unit\Services\Domain\Sms;

use HiEvents\DomainObjects\OrganizerSmsSettingDomainObject;
use HiEvents\DomainObjects\SmsMessageDomainObject;
use HiEvents\DomainObjects\SmsUnattributedMessageDomainObject;
use HiEvents\Exceptions\SmsSharedLineException;
use HiEvents\Repository\Interfaces\OrganizerSmsSettingRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsMessageRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsOptOutRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsUnattributedMessageRepositoryInterface;
use HiEvents\Services\Domain\Sms\DTO\InboundSmsDTO;
use HiEvents\Services\Domain\Sms\SmsConversationService;
use HiEvents\Services\Domain\Sms\SmsInboundService;
use HiEvents\Services\Domain\Sms\SmsLineService;
use HiEvents\Services\Domain\Sms\SmsUnattributedSmsService;
use Illuminate\Database\DatabaseManager;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class SmsUnattributedSmsServiceTest extends TestCase
{
    private SmsUnattributedMessageRepositoryInterface|MockInterface $unattributedRepository;

    private SmsMessageRepositoryInterface|MockInterface $messageRepository;

    private SmsOptOutRepositoryInterface|MockInterface $optOutRepository;

    private OrganizerSmsSettingRepositoryInterface|MockInterface $settingsRepository;

    private SmsConversationService|MockInterface $conversationService;

    private SmsInboundService|MockInterface $inboundService;

    private SmsLineService|MockInterface $lineService;

    private SmsUnattributedSmsService $service;

    private OrganizerSmsSettingDomainObject $line;

    private OrganizerSmsSettingDomainObject $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->unattributedRepository = Mockery::mock(SmsUnattributedMessageRepositoryInterface::class);
        $this->messageRepository = Mockery::mock(SmsMessageRepositoryInterface::class);
        $this->optOutRepository = Mockery::mock(SmsOptOutRepositoryInterface::class);
        $this->settingsRepository = Mockery::mock(OrganizerSmsSettingRepositoryInterface::class);
        $this->conversationService = Mockery::mock(SmsConversationService::class);
        $this->inboundService = Mockery::mock(SmsInboundService::class);
        $this->lineService = Mockery::mock(SmsLineService::class);
        $databaseManager = Mockery::mock(DatabaseManager::class);
        $databaseManager->shouldReceive('transaction')->andReturnUsing(fn ($callback) => $callback());

        $this->service = new SmsUnattributedSmsService(
            $this->unattributedRepository,
            $this->messageRepository,
            $this->optOutRepository,
            $this->settingsRepository,
            $this->conversationService,
            $this->inboundService,
            $this->lineService,
            $databaseManager,
        );

        $this->line = (new OrganizerSmsSettingDomainObject)->setId(1)->setOrganizerId(4);
        $this->member = (new OrganizerSmsSettingDomainObject)->setId(2)->setOrganizerId(8)->setSharedLineSettingsId(1);
    }

    public function test_unattributed_sms_is_stored_for_the_platform_only(): void
    {
        $this->expectNotKnown();
        $this->unattributedRepository->shouldReceive('create')
            ->once()
            ->withArgs(fn (array $attributes) => $attributes['line_settings_id'] === 1
                && $attributes['phone'] === '+262639123456'
                && $attributes['content'] === 'Bonjour, il reste des places ?'
                && $attributes['provider_message_id'] === 'msg-1'
                && $attributes['provider_timestamp'] === '2026-10-22 08:00:00'
                && $attributes['opt_out_applied'] === false)
            ->andReturn((new SmsUnattributedMessageDomainObject)->setId(5));
        $this->optOutRepository->shouldNotReceive('create');
        $this->inboundService->shouldNotReceive('recordInbound');

        $result = $this->service->record($this->line, collect([$this->line, $this->member]), $this->sms('Bonjour, il reste des places ?'), '+262639123456');

        $this->assertSame(5, $result->getId());
    }

    public function test_unattributed_stop_opts_out_every_organizer_of_the_line(): void
    {
        $this->expectNotKnown();
        $this->unattributedRepository->shouldReceive('create')
            ->once()
            ->withArgs(fn (array $attributes) => $attributes['opt_out_applied'] === true)
            ->andReturn((new SmsUnattributedMessageDomainObject)->setId(6));
        $this->conversationService->shouldReceive('isOptedOut')->with(4, '+262639123456')->andReturnFalse();
        $this->conversationService->shouldReceive('isOptedOut')->with(8, '+262639123456')->andReturnTrue();

        $this->optOutRepository->shouldReceive('create')
            ->once()
            ->with(['organizer_id' => 4, 'phone' => '+262639123456', 'source' => 'KEYWORD']);

        $this->service->record($this->line, collect([$this->line, $this->member]), $this->sms('STOP'), '+262639123456');
    }

    public function test_sms_already_stored_is_ignored(): void
    {
        $this->unattributedRepository->shouldReceive('findFirstWhere')
            ->with(['provider_message_id' => 'msg-1'])
            ->andReturn(new SmsUnattributedMessageDomainObject);
        $this->unattributedRepository->shouldNotReceive('create');

        $this->assertNull($this->service->record($this->line, collect([$this->line, $this->member]), $this->sms('STOP'), '+262639123456'));
    }

    public function test_assign_moves_the_sms_into_the_organizer_inbox(): void
    {
        $this->givenUnattributed();
        $this->settingsRepository->shouldReceive('findFirstWhere')->with(['id' => 1])->andReturn($this->line);
        $this->lineService->shouldReceive('candidates')->with($this->line)->andReturn(collect([$this->line, $this->member]));

        $this->inboundService->shouldReceive('recordInbound')
            ->once()
            ->withArgs(fn ($settings, InboundSmsDTO $sms) => $settings === $this->member
                && $sms->providerMessageId === 'msg-1'
                && $sms->contact === '+262639123456'
                && $sms->content === 'Bonjour')
            ->andReturn((new SmsMessageDomainObject)->setId(40)->setSmsConversationId(41)->setOrganizerId(8));

        $this->unattributedRepository->shouldReceive('updateFromArray')
            ->once()
            ->withArgs(fn (int $id, array $attributes) => $id === 5
                && $attributes['assigned_organizer_id'] === 8
                && $attributes['sms_message_id'] === 40
                && $attributes['assigned_at'] !== null);

        $message = $this->service->assign(5, 8);

        $this->assertSame(8, $message->getOrganizerId());
    }

    public function test_assign_refuses_an_organizer_that_does_not_use_the_line(): void
    {
        $this->givenUnattributed();
        $this->settingsRepository->shouldReceive('findFirstWhere')->with(['id' => 1])->andReturn($this->line);
        $this->lineService->shouldReceive('candidates')->andReturn(collect([$this->line, $this->member]));
        $this->inboundService->shouldNotReceive('recordInbound');

        $this->expectException(SmsSharedLineException::class);
        $this->service->assign(5, 99);
    }

    public function test_assign_refuses_an_sms_already_assigned(): void
    {
        $this->unattributedRepository->shouldReceive('findFirstWhere')->with(['id' => 5])->andReturn(
            (new SmsUnattributedMessageDomainObject)->setId(5)->setLineSettingsId(1)->setAssignedAt('2026-10-22 09:00:00')
        );
        $this->inboundService->shouldNotReceive('recordInbound');

        $this->expectException(SmsSharedLineException::class);
        $this->service->assign(5, 8);
    }

    private function givenUnattributed(): void
    {
        $this->unattributedRepository->shouldReceive('findFirstWhere')->with(['id' => 5])->andReturn(
            (new SmsUnattributedMessageDomainObject)
                ->setId(5)
                ->setLineSettingsId(1)
                ->setPhone('+262639123456')
                ->setContent('Bonjour')
                ->setProviderMessageId('msg-1')
                ->setProviderTimestamp('2026-10-22 08:00:00')
        );
    }

    private function expectNotKnown(): void
    {
        $this->unattributedRepository->shouldReceive('findFirstWhere')->andReturnNull();
        $this->messageRepository->shouldReceive('findFirstWhere')->with(['provider_message_id' => 'msg-1'])->andReturnNull();
    }

    private function sms(string $content): InboundSmsDTO
    {
        return new InboundSmsDTO(
            providerMessageId: 'msg-1',
            contact: '0639123456',
            content: $content,
            timestamp: '2026-10-22T11:00:00+03:00',
        );
    }
}
