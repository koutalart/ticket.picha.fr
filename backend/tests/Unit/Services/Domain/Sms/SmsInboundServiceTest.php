<?php

namespace Tests\Unit\Services\Domain\Sms;

use HiEvents\DomainObjects\Enums\SmsMessageAuthorType;
use HiEvents\DomainObjects\Enums\SmsMessageDirection;
use HiEvents\DomainObjects\OrganizerSmsSettingDomainObject;
use HiEvents\DomainObjects\SmsConversationDomainObject;
use HiEvents\DomainObjects\SmsMessageDomainObject;
use HiEvents\DomainObjects\Status\SmsMessageStatus;
use HiEvents\Repository\Interfaces\SmsMessageRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsOptOutRepositoryInterface;
use HiEvents\Services\Domain\Sms\DTO\InboundSmsDTO;
use HiEvents\Services\Domain\Sms\DTO\OutboundSmsEventDTO;
use HiEvents\Services\Domain\Sms\SmsConversationService;
use HiEvents\Services\Domain\Sms\SmsInboundService;
use Illuminate\Database\DatabaseManager;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class SmsInboundServiceTest extends TestCase
{
    private SmsMessageRepositoryInterface|MockInterface $messageRepository;

    private SmsOptOutRepositoryInterface|MockInterface $optOutRepository;

    private SmsConversationService|MockInterface $conversationService;

    private SmsInboundService $service;

    private OrganizerSmsSettingDomainObject $settings;

    private SmsConversationDomainObject $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->messageRepository = Mockery::mock(SmsMessageRepositoryInterface::class);
        $this->optOutRepository = Mockery::mock(SmsOptOutRepositoryInterface::class);
        $this->conversationService = Mockery::mock(SmsConversationService::class);
        $databaseManager = Mockery::mock(DatabaseManager::class);
        $databaseManager->shouldReceive('transaction')->andReturnUsing(fn ($callback) => $callback());

        $this->service = new SmsInboundService(
            $this->messageRepository,
            $this->optOutRepository,
            $this->conversationService,
            $databaseManager,
        );

        $this->settings = (new OrganizerSmsSettingDomainObject)
            ->setId(1)
            ->setAccountId(10)
            ->setOrganizerId(4)
            ->setFromNumber('+262639000000');

        $this->conversation = (new SmsConversationDomainObject)
            ->setId(7)
            ->setOrganizerId(4)
            ->setPhone('+262639123456')
            ->setStatus('OPEN')
            ->setUnreadCount(0);
    }

    public function test_duplicate_inbound_message_is_ignored(): void
    {
        $this->messageRepository->shouldReceive('findFirstWhere')
            ->with(['provider_message_id' => 'msg-1'])
            ->andReturn(new SmsMessageDomainObject);
        $this->messageRepository->shouldNotReceive('create');

        $this->assertNull($this->service->recordInbound($this->settings, $this->inbound('Salut')));
    }

    public function test_inbound_message_is_stored_and_marks_conversation_unread(): void
    {
        $this->messageRepository->shouldReceive('findFirstWhere')->andReturnNull();
        $this->conversationService->shouldReceive('findOrCreate')
            ->with($this->settings, '+262639123456')
            ->andReturn($this->conversation);

        $this->messageRepository->shouldReceive('create')
            ->once()
            ->withArgs(fn (array $attributes) => $attributes['direction'] === SmsMessageDirection::INBOUND->name
                && $attributes['author_type'] === SmsMessageAuthorType::CONTACT->name
                && $attributes['status'] === SmsMessageStatus::RECEIVED->name
                && $attributes['sms_conversation_id'] === 7
                && $attributes['provider_message_id'] === 'msg-1')
            ->andReturn((new SmsMessageDomainObject)->setId(99));

        $this->conversationService->shouldReceive('recordActivity')
            ->once()
            ->with($this->conversation, SmsMessageDirection::INBOUND, 'Il reste des places ?', true);
        $this->optOutRepository->shouldNotReceive('create');
        $this->optOutRepository->shouldNotReceive('deleteWhere');

        $message = $this->service->recordInbound($this->settings, $this->inbound('Il reste des places ?', '0639123456'));

        $this->assertSame(99, $message->getId());
    }

    public function test_stop_keyword_opts_out_the_number(): void
    {
        $this->expectStoredInbound();
        $this->conversationService->shouldReceive('isOptedOut')->with(4, '+262639123456')->andReturnFalse();

        $this->optOutRepository->shouldReceive('create')
            ->once()
            ->with([
                'organizer_id' => 4,
                'phone' => '+262639123456',
                'source' => 'KEYWORD',
            ]);

        $this->service->recordInbound($this->settings, $this->inbound('Stop'));
    }

    public function test_start_keyword_removes_the_opt_out(): void
    {
        $this->expectStoredInbound();

        $this->optOutRepository->shouldReceive('deleteWhere')
            ->once()
            ->with(['organizer_id' => 4, 'phone' => '+262639123456']);

        $this->service->recordInbound($this->settings, $this->inbound('START'));
    }

    public function test_outbound_event_updates_message_found_by_request_id(): void
    {
        $existing = (new SmsMessageDomainObject)->setId(55)->setStatus(SmsMessageStatus::PENDING->name);

        $this->messageRepository->shouldReceive('findFirstWhere')
            ->with(['provider_message_id' => 'prov-1'])
            ->andReturnNull();
        $this->messageRepository->shouldReceive('findFirstWhere')
            ->with(['organizer_id' => 4, 'request_id' => 'picha-sms-55'])
            ->andReturn($existing);

        $this->messageRepository->shouldReceive('updateFromArray')
            ->once()
            ->withArgs(fn (int $id, array $attributes) => $id === 55
                && $attributes['status'] === SmsMessageStatus::DELIVERED->name
                && $attributes['provider_message_id'] === 'prov-1')
            ->andReturn($existing);
        $this->messageRepository->shouldNotReceive('create');

        $this->service->recordOutboundEvent($this->settings, $this->outbound(SmsMessageStatus::DELIVERED, 'picha-sms-55'));
    }

    public function test_outbound_event_never_downgrades_status(): void
    {
        $existing = (new SmsMessageDomainObject)
            ->setId(55)
            ->setStatus(SmsMessageStatus::DELIVERED->name)
            ->setProviderMessageId('prov-1');

        $this->messageRepository->shouldReceive('findFirstWhere')
            ->with(['provider_message_id' => 'prov-1'])
            ->andReturn($existing);
        $this->messageRepository->shouldNotReceive('updateFromArray');

        $result = $this->service->recordOutboundEvent($this->settings, $this->outbound(SmsMessageStatus::SENT));

        $this->assertSame($existing, $result);
    }

    public function test_unknown_outbound_message_is_stored_as_campaign(): void
    {
        $this->messageRepository->shouldReceive('findFirstWhere')->andReturnNull();
        $this->conversationService->shouldReceive('findOrCreate')->andReturn($this->conversation);

        $this->messageRepository->shouldReceive('create')
            ->once()
            ->withArgs(fn (array $attributes) => $attributes['direction'] === SmsMessageDirection::OUTBOUND->name
                && $attributes['author_type'] === SmsMessageAuthorType::CAMPAIGN->name
                && $attributes['status'] === SmsMessageStatus::SENT->name
                && $attributes['content'] === 'Halloween le 31/10')
            ->andReturn((new SmsMessageDomainObject)->setId(3));

        $this->conversationService->shouldReceive('recordActivity')
            ->once()
            ->with($this->conversation, SmsMessageDirection::OUTBOUND, 'Halloween le 31/10', false);

        $this->service->recordOutboundEvent($this->settings, $this->outbound(SmsMessageStatus::SENT, 'test-halloween'));
    }

    private function expectStoredInbound(): void
    {
        $this->messageRepository->shouldReceive('findFirstWhere')->andReturnNull();
        $this->conversationService->shouldReceive('findOrCreate')->andReturn($this->conversation);
        $this->messageRepository->shouldReceive('create')->andReturn((new SmsMessageDomainObject)->setId(1));
        $this->conversationService->shouldReceive('recordActivity');
    }

    private function inbound(string $content, string $contact = '+262639123456'): InboundSmsDTO
    {
        return new InboundSmsDTO(
            providerMessageId: 'msg-1',
            contact: $contact,
            content: $content,
            timestamp: '2026-10-21T08:00:00.000Z',
        );
    }

    private function outbound(SmsMessageStatus $status, ?string $requestId = null): OutboundSmsEventDTO
    {
        return new OutboundSmsEventDTO(
            status: $status,
            providerMessageId: 'prov-1',
            contact: '+262639123456',
            content: 'Halloween le 31/10',
            requestId: $requestId,
        );
    }
}
