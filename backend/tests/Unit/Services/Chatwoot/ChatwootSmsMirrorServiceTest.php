<?php

namespace Tests\Unit\Services\Chatwoot;

use HiEvents\DomainObjects\Enums\SmsMessageAuthorType;
use HiEvents\DomainObjects\Enums\SmsMessageDirection;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\SmsConversationDomainObject;
use HiEvents\DomainObjects\SmsMessageDomainObject;
use HiEvents\DomainObjects\SmsUnattributedMessageDomainObject;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsConversationRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsMessageRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsUnattributedMessageRepositoryInterface;
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

    private SmsUnattributedMessageRepositoryInterface|MockInterface $unattributedRepository;

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
        $this->unattributedRepository = Mockery::mock(SmsUnattributedMessageRepositoryInterface::class);
        $this->cache = new CacheRepository(new ArrayStore);

        $this->service = new ChatwootSmsMirrorService(
            $this->client,
            $this->messageRepository,
            $this->conversationRepository,
            $this->organizerRepository,
            $this->cache,
            $this->unattributedRepository,
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

    public function test_sms_of_a_number_without_conversation_opens_a_conversation_for_the_number(): void
    {
        $this->unattributedRepository->shouldReceive('findFirstWhere')->with(['id' => 20])->andReturn($this->unattributed());
        $this->unattributedRepository->shouldReceive('findWhere')->once()->andReturn(collect());
        $this->client->shouldReceive('findOrCreateContact')->once()->with('+262639111111', '+262639111111')->andReturn(8);
        $this->client->shouldReceive('createConversation')->once()
            ->with(8, 'picha-sms-inconnu-262639111111', Mockery::type('array'))
            ->andReturn(70);
        $this->client->shouldReceive('createMessage')->once()->with(70, Mockery::type('string'), false, true)->andReturn(1);
        $this->client->shouldReceive('createMessage')->once()->with(70, 'Salut, c\'est pour quoi ?', true)->andReturn(950);
        $this->unattributedRepository->shouldReceive('updateFromArray')->once()
            ->with(20, ['chatwoot_conversation_id' => 70, 'chatwoot_message_id' => 950]);

        $this->service->mirrorUnattributed(20);
    }

    public function test_next_sms_of_the_same_number_goes_to_its_conversation(): void
    {
        $this->unattributedRepository->shouldReceive('findFirstWhere')->andReturn($this->unattributed());
        $this->unattributedRepository->shouldReceive('findWhere')->once()
            ->andReturn(collect([$this->unattributed()->setId(19)->setChatwootConversationId(70)->setChatwootMessageId(940)]));
        $this->client->shouldNotReceive('findOrCreateContact');
        $this->client->shouldNotReceive('createConversation');
        $this->client->shouldReceive('createMessage')->once()->with(70, 'Salut, c\'est pour quoi ?', true)->andReturn(951);
        $this->unattributedRepository->shouldReceive('updateFromArray')->once()
            ->with(20, ['chatwoot_conversation_id' => 70, 'chatwoot_message_id' => 951]);

        $this->service->mirrorUnattributed(20);
    }

    public function test_sms_already_attributed_or_already_copied_is_skipped(): void
    {
        $this->client->shouldNotReceive('createMessage');
        $this->unattributedRepository->shouldNotReceive('updateFromArray');
        $this->unattributedRepository->shouldReceive('findFirstWhere')->andReturn(
            $this->unattributed()->setSmsMessageId(10),
            $this->unattributed()->setChatwootMessageId(950),
        );

        $this->service->mirrorUnattributed(20);
        $this->service->mirrorUnattributed(20);
    }

    public function test_attributed_sms_already_copied_is_not_copied_twice_and_links_the_conversation(): void
    {
        $message = $this->message(SmsMessageDirection::INBOUND)->setProviderMessageId('prov-1');
        $this->messageRepository->shouldReceive('findFirstWhere')->andReturn($message);
        $this->conversationRepository->shouldReceive('findFirstWhere')->andReturn($this->conversation(null));
        $this->unattributedRepository->shouldReceive('findFirstWhere')->once()
            ->with(['provider_message_id' => 'prov-1', ['chatwoot_message_id', 'not null', null]])
            ->andReturn($this->unattributed()->setChatwootConversationId(70)->setChatwootMessageId(950));
        $this->organizerRepository->shouldReceive('findFirstWhere')->andReturn((new OrganizerDomainObject)->setId(4)->setName('Innocent Event'));
        $this->conversationRepository->shouldReceive('updateFromArray')->once()->with(3, ['chatwoot_conversation_id' => 70]);
        $this->client->shouldNotReceive('createConversation');
        $this->client->shouldReceive('createMessage')->once()->with(70, Mockery::type('string'), false, true)->andReturn(2);
        $this->messageRepository->shouldReceive('updateFromArray')->once()->with(10, ['chatwoot_message_id' => 950]);

        $this->service->mirror(10);
    }

    public function test_attributed_sms_not_yet_copied_is_mirrored_normally(): void
    {
        $message = $this->message(SmsMessageDirection::INBOUND)->setProviderMessageId('prov-1');
        $this->messageRepository->shouldReceive('findFirstWhere')->andReturn($message);
        $this->conversationRepository->shouldReceive('findFirstWhere')->andReturn($this->conversation(55));
        $this->unattributedRepository->shouldReceive('findFirstWhere')->once()->andReturnNull();
        $this->client->shouldReceive('createMessage')->once()->with(55, 'Bonjour', true)->andReturn(903);
        $this->messageRepository->shouldReceive('updateFromArray')->once()->with(10, ['chatwoot_message_id' => 903]);

        $this->service->mirror(10);
    }

    private function unattributed(): SmsUnattributedMessageDomainObject
    {
        return (new SmsUnattributedMessageDomainObject)
            ->setId(20)
            ->setLineSettingsId(1)
            ->setPhone('+262639111111')
            ->setContent('Salut, c\'est pour quoi ?')
            ->setProviderMessageId('prov-1')
            ->setSmsMessageId(null)
            ->setChatwootConversationId(null)
            ->setChatwootMessageId(null);
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
