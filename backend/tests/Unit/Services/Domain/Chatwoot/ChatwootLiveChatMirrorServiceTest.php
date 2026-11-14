<?php

namespace Tests\Unit\Services\Domain\Chatwoot;

use HiEvents\DomainObjects\LiveChatMessageDomainObject;
use HiEvents\DomainObjects\LiveChatSessionDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Repository\Interfaces\LiveChatMessageRepositoryInterface;
use HiEvents\Repository\Interfaces\LiveChatSessionRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Services\Domain\Chatwoot\ChatwootLiveChatMirrorService;
use HiEvents\Services\Domain\Chatwoot\ChatwootLiveChatReplyService;
use HiEvents\Services\Domain\LiveChat\LiveChatService;
use HiEvents\Services\Infrastructure\Chatwoot\ChatwootClient;
use HiEvents\Services\Infrastructure\UneAnicka\DTO\UneAnickaDecisionDTO;
use HiEvents\Services\Infrastructure\UneAnicka\UneAnickaClient;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class ChatwootLiveChatMirrorServiceTest extends TestCase
{
    private ChatwootClient|MockInterface $client;

    private LiveChatMessageRepositoryInterface|MockInterface $messageRepository;

    private LiveChatSessionRepositoryInterface|MockInterface $sessionRepository;

    private CacheRepository $cache;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = Mockery::mock(ChatwootClient::class);
        $this->client->shouldReceive('isLiveChatEnabled')->andReturnTrue()->byDefault();
        $this->client->shouldReceive('liveChatInboxId')->andReturn(18)->byDefault();
        $this->messageRepository = Mockery::mock(LiveChatMessageRepositoryInterface::class);
        $this->sessionRepository = Mockery::mock(LiveChatSessionRepositoryInterface::class);
        $this->sessionRepository->shouldReceive('findById')->with(5)->andReturn($this->chatSession());
        $this->cache = new CacheRepository(new ArrayStore);
    }

    private function chatSession(): LiveChatSessionDomainObject
    {
        return (new LiveChatSessionDomainObject)->setId(5)->setOrganizerId(8)->setPublicId('abc')->setVisitorPhone('+262639000001');
    }

    private function mirror(): ChatwootLiveChatMirrorService
    {
        $organizers = Mockery::mock(OrganizerRepositoryInterface::class);
        $organizers->shouldReceive('findFirstWhere')->andReturn((new OrganizerDomainObject)->setId(8)->setName('Mayotte La 1ère'));

        return new ChatwootLiveChatMirrorService($this->client, $this->messageRepository, $this->sessionRepository, $organizers, $this->cache);
    }

    private function message(int $id, string $author, string $content): void
    {
        $this->messageRepository->shouldReceive('findFirstWhere')->with(['id' => $id])
            ->andReturn((new LiveChatMessageDomainObject)->setId($id)->setLiveChatSessionId(5)->setAuthorType($author)->setContent($content));
    }

    public function test_a_chat_gets_one_conversation_in_the_live_chat_inbox_and_every_message_is_copied(): void
    {
        $this->message(1, 'VISITOR', 'Bonjour, il reste des places ?');
        $this->message(2, 'AGENT', 'Oui, il en reste.');
        $this->client->shouldReceive('findOrCreateVisitorContact')->once()->with('+262639000001', null, Mockery::type('string'))->andReturn(40);
        $this->client->shouldReceive('createConversation')->once()
            ->withArgs(fn (int $contact, string $source, array $attributes, ?int $inbox) => $contact === 40 && $source === 'picha-chat-5' && $inbox === 18)
            ->andReturn(700);
        $this->client->shouldReceive('createMessage')->once()->with(700, Mockery::type('string'), false, true)->andReturn(1);
        $this->client->shouldReceive('createMessage')->once()->with(700, 'Bonjour, il reste des places ?', true)->andReturn(2);
        $this->client->shouldReceive('createMessage')->once()->with(700, 'Oui, il en reste.', false)->andReturn(3);

        $this->mirror()->mirror(1);
        $this->mirror()->mirror(2);

        $this->assertSame(5, $this->mirror()->sessionIdFor(700));
    }

    public function test_nothing_is_copied_without_a_live_chat_inbox(): void
    {
        $this->client->shouldReceive('isLiveChatEnabled')->andReturnFalse();
        $this->client->shouldNotReceive('createMessage');

        $this->mirror()->mirror(1);
        $this->assertTrue(true);
    }

    public function test_a_human_reply_typed_in_chatwoot_reaches_the_visitor_and_tells_the_router(): void
    {
        $this->cache->put(ChatwootLiveChatMirrorService::conversationKey(700), 5, 60);
        $liveChat = Mockery::mock(LiveChatService::class);
        $liveChat->shouldReceive('record')->once()->withArgs(fn ($session, $author, string $content) => $session->getId() === 5 && $content === 'Je regarde ça');
        $router = Mockery::mock(UneAnickaClient::class);
        $router->shouldReceive('decide')->once()
            ->withArgs(fn (string $system, string $contact, string $channel, string $text, ?int $organizer, string $kind) => $system === 'humain-chatwoot' && $contact === '+262639000001' && $kind === 'humain')
            ->andReturn(new UneAnickaDecisionDTO(true, 'R1', 'humain'));

        $service = new ChatwootLiveChatReplyService($this->mirror(), $this->sessionRepository, $liveChat, $this->client, $router);

        $this->assertTrue($service->isLiveChatInbox(18));
        $this->assertFalse($service->isLiveChatInbox(2));
        $service->relay(700, 'Je regarde ça');
    }
}
