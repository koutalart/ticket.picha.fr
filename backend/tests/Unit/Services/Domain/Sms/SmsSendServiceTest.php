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
use HiEvents\Services\Domain\Sms\SmsSendService;
use HiEvents\Services\Infrastructure\HttpSms\HttpSmsClient;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class SmsSendServiceTest extends TestCase
{
    private OrganizerSmsSettingRepositoryInterface|MockInterface $settingsRepository;

    private SmsMessageRepositoryInterface|MockInterface $messageRepository;

    private SmsConversationRepositoryInterface|MockInterface $conversationRepository;

    private SmsConversationService|MockInterface $conversationService;

    private HttpSmsClient|MockInterface $client;

    private SmsSendService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->settingsRepository = Mockery::mock(OrganizerSmsSettingRepositoryInterface::class);
        $this->messageRepository = Mockery::mock(SmsMessageRepositoryInterface::class);
        $this->conversationRepository = Mockery::mock(SmsConversationRepositoryInterface::class);
        $this->conversationService = Mockery::mock(SmsConversationService::class);
        $this->client = Mockery::mock(HttpSmsClient::class);

        $this->service = new SmsSendService(
            $this->settingsRepository,
            $this->messageRepository,
            $this->conversationRepository,
            $this->conversationService,
            $this->client,
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
