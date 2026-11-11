<?php

namespace Tests\Unit\Services\Chatwoot;

use HiEvents\DomainObjects\Enums\SmsMessageAuthorType;
use HiEvents\DomainObjects\Enums\SmsMessageDirection;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\SmsConversationDomainObject;
use HiEvents\DomainObjects\SmsMessageDomainObject;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsConversationRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsMessageRepositoryInterface;
use HiEvents\Services\Domain\Chatwoot\ChatwootSmsMirrorService;
use HiEvents\Services\Infrastructure\Chatwoot\ChatwootClient;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class ChatwootSmsMirrorServiceTest extends TestCase
{
    private ChatwootClient|MockInterface $client;

    private SmsMessageRepositoryInterface|MockInterface $messageRepository;

    private SmsConversationRepositoryInterface|MockInterface $conversationRepository;

    private OrganizerRepositoryInterface|MockInterface $organizerRepository;

    private CacheRepository $cache;

    private ChatwootSmsMirrorService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = Mockery::mock(ChatwootClient::class);
        $this->client->shouldReceive('isSmsGatewayEnabled')->andReturn(true);
        $this->messageRepository = Mockery::mock(SmsMessageRepositoryInterface::class);
        $this->conversationRepository = Mockery::mock(SmsConversationRepositoryInterface::class);
        $this->organizerRepository = Mockery::mock(OrganizerRepositoryInterface::class);
        $this->cache = new CacheRepository(new ArrayStore);

        $this->service = new ChatwootSmsMirrorService(
            $this->client,
            $this->messageRepository,
            $this->conversationRepository,
            $this->organizerRepository,
            $this->cache,
        );
    }

    public function test_inbound_sms_of_a_linked_conversation_is_copied_as_incoming(): void
    {
        $this->messageRepository->shouldReceive('findFirstWhere')->andReturn($this->message(SmsMessageDirection::INBOUND));
        $this->conversationRepository->shouldReceive('findFirstWhere')->andReturn($this->conversation(55));
        $this->client->shouldReceive('createMessage')->once()->with(55, 'Bonjour', true)->andReturn(900);
        $this->messageRepository->shouldReceive('updateFromArray')->once()->with(10, ['chatwoot_message_id' => 900]);

        $this->service->mirror(10);
    }

    public function test_reply_of_anicka_is_labelled_and_marked_pending_to_ignore_its_echo(): void
    {
        $message = $this->message(SmsMessageDirection::OUTBOUND)->setAuthorType(SmsMessageAuthorType::AGENT->name);
        $this->messageRepository->shouldReceive('findFirstWhere')->andReturn($message);
        $this->conversationRepository->shouldReceive('findFirstWhere')->andReturn($this->conversation(55));
        $this->client->shouldReceive('createMessage')->once()->with(55, '[Anicka] Bonjour', false)->andReturn(901);
        $this->messageRepository->shouldReceive('updateFromArray')->once();

        $this->service->mirror(10);

        $this->assertTrue($this->cache->has(ChatwootSmsMirrorService::pendingKey(55, '[Anicka] Bonjour')));
    }

    public function test_first_message_opens_the_chatwoot_conversation(): void
    {
        $this->messageRepository->shouldReceive('findFirstWhere')->andReturn($this->message(SmsMessageDirection::INBOUND));
        $this->conversationRepository->shouldReceive('findFirstWhere')->andReturn($this->conversation(null));
        $this->organizerRepository->shouldReceive('findFirstWhere')->andReturn((new OrganizerDomainObject)->setId(4)->setName('Innocent Event'));
        $this->client->shouldReceive('findOrCreateContact')->once()->with('+262639000000', 'Awa')->andReturn(7);
        $this->client->shouldReceive('createConversation')->once()
            ->with(7, 'picha-sms-live-3', ['organisateur' => 'Innocent Event', 'conversation_picha' => 3])
            ->andReturn(60);
        $this->conversationRepository->shouldReceive('updateFromArray')->once()->with(3, ['chatwoot_conversation_id' => 60]);
        $this->client->shouldReceive('createMessage')->once()->with(60, Mockery::type('string'), false, true)->andReturn(1);
        $this->client->shouldReceive('createMessage')->once()->with(60, 'Bonjour', true)->andReturn(902);
        $this->messageRepository->shouldReceive('updateFromArray')->once()->with(10, ['chatwoot_message_id' => 902]);

        $this->service->mirror(10);
    }

    public function test_supervision_and_already_copied_messages_are_skipped(): void
    {
        $this->client->shouldNotReceive('createMessage');

        $this->messageRepository->shouldReceive('findFirstWhere')->andReturn(
            $this->message(SmsMessageDirection::OUTBOUND)->setIsSupervision(true),
            $this->message(SmsMessageDirection::OUTBOUND)->setChatwootMessageId(5),
        );

        $this->service->mirror(10);
        $this->service->mirror(10);
    }

    public function test_messages_created_while_mirroring_is_suspended_are_flagged(): void
    {
        $this->assertFalse(ChatwootSmsMirrorService::isSuppressed());
        $inside = ChatwootSmsMirrorService::withoutMirroring(fn () => ChatwootSmsMirrorService::isSuppressed());
        $this->assertTrue($inside);
        $this->assertFalse(ChatwootSmsMirrorService::isSuppressed());
    }

    private function message(SmsMessageDirection $direction): SmsMessageDomainObject
    {
        return (new SmsMessageDomainObject)
            ->setId(10)
            ->setSmsConversationId(3)
            ->setDirection($direction->name)
            ->setAuthorType($direction === SmsMessageDirection::INBOUND ? SmsMessageAuthorType::CONTACT->name : SmsMessageAuthorType::HUMAN->name)
            ->setContent('Bonjour')
            ->setIsSupervision(false)
            ->setChannel(null)
            ->setChatwootMessageId(null);
    }

    private function conversation(?int $chatwootId): SmsConversationDomainObject
    {
        return (new SmsConversationDomainObject)
            ->setId(3)
            ->setOrganizerId(4)
            ->setPhone('+262639000000')
            ->setContactName('Awa')
            ->setIsTeam(false)
            ->setChatwootConversationId($chatwootId);
    }
}
