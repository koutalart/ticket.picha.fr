<?php

namespace Tests\Unit\Services\Domain\Sms;

use HiEvents\DomainObjects\OrganizerSmsSettingDomainObject;
use HiEvents\DomainObjects\SmsConversationDomainObject;
use HiEvents\DomainObjects\SmsMessageDomainObject;
use HiEvents\DomainObjects\Status\SmsMessageStatus;
use HiEvents\Exceptions\SmsGatewayException;
use HiEvents\Exceptions\SmsGatewayNotConfiguredException;
use HiEvents\Exceptions\SmsRecipientOptedOutException;
use HiEvents\Repository\Interfaces\OrganizerSmsSettingRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsConversationRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsMessageRepositoryInterface;
use HiEvents\Services\Domain\Sms\DTO\SendSmsDTO;
use HiEvents\Services\Domain\Sms\SmsConversationService;
use HiEvents\Services\Domain\Sms\SmsLineService;
use HiEvents\Services\Domain\Sms\SmsFileStatusService;
use HiEvents\Services\Domain\Sms\SmsSendService;
use HiEvents\Services\Infrastructure\SmsFile\SmsFileClient;
use HiEvents\Services\Infrastructure\HttpSms\HttpSmsClient;
use Mockery;
use Mockery\MockInterface;
use Psr\Log\NullLogger;
use Tests\TestCase;

class SmsSendServiceTest extends TestCase
{
    private OrganizerSmsSettingRepositoryInterface|MockInterface $settingsRepository;

    private SmsMessageRepositoryInterface|MockInterface $messageRepository;

    private SmsConversationRepositoryInterface|MockInterface $conversationRepository;

    private SmsConversationService|MockInterface $conversationService;

    private HttpSmsClient|MockInterface $client;

    private SmsLineService|MockInterface $lineService;

    private SmsSendService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->settingsRepository = Mockery::mock(OrganizerSmsSettingRepositoryInterface::class);
        $this->messageRepository = Mockery::mock(SmsMessageRepositoryInterface::class);
        $this->conversationRepository = Mockery::mock(SmsConversationRepositoryInterface::class);
        $this->conversationService = Mockery::mock(SmsConversationService::class);
        $this->client = Mockery::mock(HttpSmsClient::class);
        $this->lineService = Mockery::mock(SmsLineService::class);
        $this->lineService->shouldReceive('resolveLine')->andReturnUsing(fn ($settings) => $settings)->byDefault();

        $fileClient = Mockery::mock(SmsFileClient::class);
        $fileClient->shouldReceive('isEnabled')->andReturnFalse();

        $this->service = new SmsSendService(
            $this->settingsRepository,
            $this->messageRepository,
            $this->conversationRepository,
            $this->conversationService,
            $this->client,
            $this->lineService,
            $fileClient,
            Mockery::mock(SmsFileStatusService::class),
            new NullLogger,
        );
    }

    public function test_refuses_when_gateway_is_not_configured(): void
    {
        $this->settingsRepository->shouldReceive('findFirstWhere')
            ->andReturn((new OrganizerSmsSettingDomainObject)->setOrganizerId(4));
        $this->client->shouldNotReceive('sendMessage');

        $this->expectException(SmsGatewayNotConfiguredException::class);
        $this->service->send($this->dto());
    }

    public function test_refuses_opted_out_recipient(): void
    {
        $this->settingsRepository->shouldReceive('findFirstWhere')->andReturn($this->settings());
        $this->conversationService->shouldReceive('isOptedOut')->with(4, '+262639123456')->andReturnTrue();
        $this->messageRepository->shouldNotReceive('create');
        $this->client->shouldNotReceive('sendMessage');

        $this->expectException(SmsRecipientOptedOutException::class);
        $this->service->send($this->dto());
    }

    public function test_sends_through_http_sms_with_request_id(): void
    {
        $this->expectMessageCreated();

        $this->client->shouldReceive('sendMessage')
            ->once()
            ->with('api-key-123', '+262639000000', '+262639123456', 'Bonjour', 'picha-sms-12')
            ->andReturn('prov-12');

        $this->messageRepository->shouldReceive('updateFromArray')
            ->with(12, ['provider_message_id' => 'prov-12'])
            ->once()
            ->andReturn((new SmsMessageDomainObject)->setId(12)->setProviderMessageId('prov-12'));

        $message = $this->service->send($this->dto());

        $this->assertSame('prov-12', $message->getProviderMessageId());
    }

    public function test_marks_message_failed_when_gateway_rejects(): void
    {
        $this->expectMessageCreated();

        $this->client->shouldReceive('sendMessage')->andThrow(new SmsGatewayException('phone offline'));
        $this->messageRepository->shouldReceive('updateFromArray')
            ->once()
            ->with(12, [
                'status' => SmsMessageStatus::FAILED->name,
                'error_message' => 'phone offline',
            ]);

        $this->expectException(SmsGatewayException::class);
        $this->service->send($this->dto());
    }

    public function test_member_of_a_shared_line_sends_with_the_line_but_keeps_its_own_conversation(): void
    {
        $member = (new OrganizerSmsSettingDomainObject)
            ->setId(2)
            ->setAccountId(20)
            ->setOrganizerId(8)
            ->setSharedLineSettingsId(1);
        $conversation = (new SmsConversationDomainObject)->setId(70)->setOrganizerId(8)->setPhone('+262639123456');

        $this->settingsRepository->shouldReceive('findFirstWhere')->with(['organizer_id' => 8])->andReturn($member);
        $this->lineService->shouldReceive('resolveLine')->with($member)->andReturn($this->settings());
        $this->conversationService->shouldReceive('isOptedOut')->with(8, '+262639123456')->andReturnFalse();
        $this->conversationService->shouldReceive('findOrCreate')->once()->with($member, '+262639123456')->andReturn($conversation);
        $this->conversationService->shouldReceive('recordActivity');
        $this->conversationRepository->shouldReceive('updateFromArray');

        $this->messageRepository->shouldReceive('create')
            ->once()
            ->withArgs(fn (array $attributes) => $attributes['organizer_id'] === 8 && $attributes['sms_conversation_id'] === 70)
            ->andReturn((new SmsMessageDomainObject)->setId(13));
        $this->messageRepository->shouldReceive('updateFromArray')->with(13, ['request_id' => 'picha-sms-13'])->once();

        $this->client->shouldReceive('sendMessage')
            ->once()
            ->with('api-key-123', '+262639000000', '+262639123456', 'Bonjour', 'picha-sms-13')
            ->andReturn('prov-13');
        $this->messageRepository->shouldReceive('updateFromArray')
            ->with(13, ['provider_message_id' => 'prov-13'])
            ->once()
            ->andReturn((new SmsMessageDomainObject)->setId(13)->setOrganizerId(8)->setProviderMessageId('prov-13'));

        $message = $this->service->send(new SendSmsDTO(organizerId: 8, phone: '0639123456', content: 'Bonjour', sentByUserId: 3));

        $this->assertSame(8, $message->getOrganizerId());
        $this->assertSame('prov-13', $message->getProviderMessageId());
    }

    private function expectMessageCreated(): void
    {
        $conversation = (new SmsConversationDomainObject)->setId(7)->setPhone('+262639123456');

        $this->settingsRepository->shouldReceive('findFirstWhere')->andReturn($this->settings());
        $this->conversationService->shouldReceive('isOptedOut')->andReturnFalse();
        $this->conversationService->shouldReceive('findOrCreate')->andReturn($conversation);
        $this->conversationService->shouldReceive('recordActivity');
        $this->conversationRepository->shouldReceive('updateFromArray');

        $this->messageRepository->shouldReceive('create')
            ->once()
            ->withArgs(fn (array $attributes) => $attributes['status'] === SmsMessageStatus::PENDING->name
                && $attributes['author_type'] === 'HUMAN'
                && $attributes['sent_by_user_id'] === 3)
            ->andReturn((new SmsMessageDomainObject)->setId(12));

        $this->messageRepository->shouldReceive('updateFromArray')
            ->with(12, ['request_id' => 'picha-sms-12'])
            ->once();
    }

    private function settings(): OrganizerSmsSettingDomainObject
    {
        return (new OrganizerSmsSettingDomainObject)
            ->setId(1)
            ->setAccountId(10)
            ->setOrganizerId(4)
            ->setApiKey('api-key-123')
            ->setFromNumber('+262639000000');
    }

    private function dto(): SendSmsDTO
    {
        return new SendSmsDTO(
            organizerId: 4,
            phone: '0639123456',
            content: 'Bonjour',
            sentByUserId: 3,
        );
    }
}
