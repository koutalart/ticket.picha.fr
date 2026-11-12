<?php

namespace Tests\Unit\Services\Chatwoot;

use HiEvents\DomainObjects\SmsConversationDomainObject;
use HiEvents\DomainObjects\SmsMessageDomainObject;
use HiEvents\Exceptions\ChatwootWebhookUnauthorizedException;
use HiEvents\Exceptions\SmsRecipientOptedOutException;
use HiEvents\Repository\Interfaces\SmsConversationRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsMessageRepositoryInterface;
use HiEvents\Services\Application\Handlers\Chatwoot\HandleChatwootWebhookHandler;
use HiEvents\Services\Application\Handlers\Sms\SendSmsReplyHandler;
use HiEvents\Services\Domain\Chatwoot\ChatwootSmsMirrorService;
use HiEvents\Services\Domain\Chatwoot\ChatwootUnknownNumberReplyService;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Config\Repository as ConfigRepository;
use Mockery;
use Mockery\MockInterface;
use Psr\Log\NullLogger;
use Tests\TestCase;

class ChatwootWebhookHandlerTest extends TestCase
{
    private const TOKEN = 'abcdefghijklmnopqrstuvwxyz012345';

    private CacheRepository $cache;

    private SmsConversationRepositoryInterface|MockInterface $conversationRepository;

    private SmsMessageRepositoryInterface|MockInterface $messageRepository;

    private SendSmsReplyHandler|MockInterface $replyHandler;

    private ChatwootSmsMirrorService|MockInterface $mirrorService;

    private ChatwootUnknownNumberReplyService|MockInterface $unknownNumberReplyService;

    private HandleChatwootWebhookHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cache = new CacheRepository(new ArrayStore);
        $this->conversationRepository = Mockery::mock(SmsConversationRepositoryInterface::class);
        $this->messageRepository = Mockery::mock(SmsMessageRepositoryInterface::class);
        $this->replyHandler = Mockery::mock(SendSmsReplyHandler::class);
        $this->mirrorService = Mockery::mock(ChatwootSmsMirrorService::class);
        $this->unknownNumberReplyService = Mockery::mock(ChatwootUnknownNumberReplyService::class);

        $this->handler = new HandleChatwootWebhookHandler(
            new ConfigRepository(['services' => ['chatwoot' => ['webhook_token' => self::TOKEN, 'sms_inbox_id' => 2, 'gateway_user_id' => 0]]]),
            $this->cache,
            $this->conversationRepository,
            $this->messageRepository,
            $this->replyHandler,
            $this->mirrorService,
            new NullLogger,
            $this->unknownNumberReplyService,
        );
    }

    public function test_wrong_token_is_refused(): void
    {
        $this->expectException(ChatwootWebhookUnauthorizedException::class);

        $this->handler->handle('wrong-token-wrong-token-wrong-token', $this->event());
    }

    public function test_agent_reply_is_sent_as_sms_without_being_copied_back(): void
    {
        $this->messageRepository->shouldReceive('findFirstWhere')->once()->andReturn(null);
        $this->conversationRepository->shouldReceive('findFirstWhere')->once()->with(['chatwoot_conversation_id' => 55])
            ->andReturn((new SmsConversationDomainObject)->setId(3)->setOrganizerId(4));
        $this->replyHandler->shouldReceive('handle')->once()
            ->with(4, 3, 'On vous rappelle', null)
            ->andReturnUsing(function () {
                $this->assertTrue(ChatwootSmsMirrorService::isSuppressed());

                return (new SmsMessageDomainObject)->setId(70);
            });
        $this->messageRepository->shouldReceive('updateFromArray')->once()->with(70, ['chatwoot_message_id' => 900]);

        $this->handler->handle(self::TOKEN, $this->event());
    }

    public function test_echo_of_a_message_copied_by_picha_is_ignored(): void
    {
        $this->cache->put(ChatwootSmsMirrorService::pendingKey(55, 'On vous rappelle'), true, 60);
        $this->replyHandler->shouldNotReceive('handle');

        $this->handler->handle(self::TOKEN, $this->event());
    }

    public function test_incoming_private_bot_and_other_inbox_messages_are_ignored(): void
    {
        $this->replyHandler->shouldNotReceive('handle');

        $this->handler->handle(self::TOKEN, $this->event(['message_type' => 'incoming']));
        $this->handler->handle(self::TOKEN, $this->event(['private' => true]));
        $this->handler->handle(self::TOKEN, $this->event(['sender' => ['id' => 1, 'type' => 'agent_bot']]));
        $this->handler->handle(self::TOKEN, $this->event(['inbox' => ['id' => 1]]));
        $this->handler->handle(self::TOKEN, $this->event(['event' => 'conversation_updated']));
    }

    public function test_opted_out_number_gets_a_private_note(): void
    {
        $this->messageRepository->shouldReceive('findFirstWhere')->andReturn(null);
        $this->conversationRepository->shouldReceive('findFirstWhere')->andReturn((new SmsConversationDomainObject)->setId(3)->setOrganizerId(4));
        $this->replyHandler->shouldReceive('handle')->andThrow(new SmsRecipientOptedOutException('stop'));
        $this->mirrorService->shouldReceive('postNote')->once()->with(55, Mockery::type('string'));

        $this->handler->handle(self::TOKEN, $this->event());
    }

    public function test_reply_in_the_conversation_of_a_number_without_conversation_is_sent_from_its_line(): void
    {
        $this->messageRepository->shouldReceive('findFirstWhere')->andReturn(null);
        $this->conversationRepository->shouldReceive('findFirstWhere')->andReturn(null);
        $this->replyHandler->shouldNotReceive('handle');
        $this->unknownNumberReplyService->shouldReceive('reply')->once()->with(55, 900, 'On vous rappelle')->andReturnTrue();
        $this->mirrorService->shouldNotReceive('postNote');

        $this->handler->handle(self::TOKEN, $this->event());
    }

    public function test_reply_in_an_unknown_chatwoot_conversation_gets_a_private_note(): void
    {
        $this->messageRepository->shouldReceive('findFirstWhere')->andReturn(null);
        $this->conversationRepository->shouldReceive('findFirstWhere')->andReturn(null);
        $this->unknownNumberReplyService->shouldReceive('reply')->once()->andReturnFalse();
        $this->mirrorService->shouldReceive('postNote')->once()->with(55, Mockery::type('string'));

        $this->handler->handle(self::TOKEN, $this->event());
    }

    public function test_reply_to_a_number_without_conversation_that_said_stop_gets_a_private_note(): void
    {
        $this->messageRepository->shouldReceive('findFirstWhere')->andReturn(null);
        $this->conversationRepository->shouldReceive('findFirstWhere')->andReturn(null);
        $this->unknownNumberReplyService->shouldReceive('reply')->once()->andThrow(new SmsRecipientOptedOutException('stop'));
        $this->mirrorService->shouldReceive('postNote')->once()->with(55, Mockery::type('string'));

        $this->handler->handle(self::TOKEN, $this->event());
    }

    private function event(array $override = []): array
    {
        return array_merge([
            'event' => 'message_created',
            'id' => 900,
            'content' => 'On vous rappelle',
            'message_type' => 'outgoing',
            'private' => false,
            'sender' => ['id' => 1, 'type' => 'user'],
            'inbox' => ['id' => 2],
            'conversation' => ['id' => 55],
        ], $override);
    }
}
