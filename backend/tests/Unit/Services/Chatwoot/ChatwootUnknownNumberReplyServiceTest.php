<?php

namespace Tests\Unit\Services\Chatwoot;

use HiEvents\DomainObjects\OrganizerSmsSettingDomainObject;
use HiEvents\DomainObjects\SmsUnattributedMessageDomainObject;
use HiEvents\DomainObjects\Status\SmsMessageStatus;
use HiEvents\Exceptions\SmsGatewayException;
use HiEvents\Exceptions\SmsGatewayNotConfiguredException;
use HiEvents\Exceptions\SmsRecipientOptedOutException;
use HiEvents\Repository\Interfaces\OrganizerSmsSettingRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsUnattributedMessageRepositoryInterface;
use HiEvents\Services\Domain\Chatwoot\ChatwootSmsMirrorService;
use HiEvents\Services\Domain\Chatwoot\ChatwootUnknownNumberReplyService;
use HiEvents\Services\Domain\Sms\SmsConversationService;
use HiEvents\Services\Infrastructure\HttpSms\HttpSmsClient;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class ChatwootUnknownNumberReplyServiceTest extends TestCase
{
    private SmsUnattributedMessageRepositoryInterface|MockInterface $unattributedRepository;

    private OrganizerSmsSettingRepositoryInterface|MockInterface $settingsRepository;

    private SmsConversationService|MockInterface $conversationService;

    private HttpSmsClient|MockInterface $httpSmsClient;

    private ChatwootSmsMirrorService|MockInterface $mirrorService;

    private ChatwootUnknownNumberReplyService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->unattributedRepository = Mockery::mock(SmsUnattributedMessageRepositoryInterface::class);
        $this->settingsRepository = Mockery::mock(OrganizerSmsSettingRepositoryInterface::class);
        $this->conversationService = Mockery::mock(SmsConversationService::class);
        $this->conversationService->shouldReceive('isOptedOut')->andReturnFalse()->byDefault();
        $this->httpSmsClient = Mockery::mock(HttpSmsClient::class);
        $this->mirrorService = Mockery::mock(ChatwootSmsMirrorService::class);

        $this->service = new ChatwootUnknownNumberReplyService(
            $this->unattributedRepository,
            $this->settingsRepository,
            $this->conversationService,
            $this->httpSmsClient,
            $this->mirrorService,
            new CacheRepository(new ArrayStore),
        );
    }

    public function test_reply_leaves_by_sms_from_the_line_that_received_the_number(): void
    {
        $this->expectNumber();
        $this->httpSmsClient->shouldReceive('sendMessage')->once()
            ->with('key', '+262639000000', '+262639111111', 'On vous rappelle', 'picha-chatwoot-70-900')
            ->andReturn('prov-1');

        $this->assertTrue($this->service->reply(70, 900, 'On vous rappelle'));
    }

    public function test_the_same_chatwoot_message_is_sent_only_once(): void
    {
        $this->expectNumber();
        $this->httpSmsClient->shouldReceive('sendMessage')->once()->andReturn('prov-1');

        $this->service->reply(70, 900, 'On vous rappelle');
        $this->assertTrue($this->service->reply(70, 900, 'On vous rappelle'));
    }

    public function test_a_failed_send_can_be_retried(): void
    {
        $this->expectNumber();
        $this->httpSmsClient->shouldReceive('sendMessage')->once()->andThrow(new SmsGatewayException('down'));
        $this->httpSmsClient->shouldReceive('sendMessage')->once()->andReturn('prov-1');

        try {
            $this->service->reply(70, 900, 'On vous rappelle');
            $this->fail('The gateway error should be thrown');
        } catch (SmsGatewayException) {
        }

        $this->assertTrue($this->service->reply(70, 900, 'On vous rappelle'));
    }

    public function test_conversation_that_is_not_a_number_without_conversation_is_left_alone(): void
    {
        $this->unattributedRepository->shouldReceive('findWhere')->andReturn(collect());
        $this->httpSmsClient->shouldNotReceive('sendMessage');

        $this->assertFalse($this->service->reply(71, 900, 'On vous rappelle'));
    }

    public function test_number_that_said_stop_is_not_sent_to(): void
    {
        $this->expectNumber();
        $this->conversationService->shouldReceive('isOptedOut')->with(1, '+262639111111')->andReturnTrue();
        $this->httpSmsClient->shouldNotReceive('sendMessage');

        $this->expectException(SmsRecipientOptedOutException::class);

        $this->service->reply(70, 900, 'On vous rappelle');
    }

    public function test_line_without_gateway_is_refused(): void
    {
        $this->unattributedRepository->shouldReceive('findWhere')->andReturn(collect([$this->unattributed()]));
        $this->settingsRepository->shouldReceive('findFirstWhere')->andReturn((new OrganizerSmsSettingDomainObject)->setId(5)->setOrganizerId(1));
        $this->httpSmsClient->shouldNotReceive('sendMessage');

        $this->expectException(SmsGatewayNotConfiguredException::class);

        $this->service->reply(70, 900, 'On vous rappelle');
    }

    public function test_failed_delivery_is_noted_in_chatwoot(): void
    {
        $this->mirrorService->shouldReceive('postNote')->once()->with(70, Mockery::on(fn ($note) => str_contains($note, 'offline')));

        $this->assertTrue($this->service->handleDeliveryEvent('picha-chatwoot-70-900', SmsMessageStatus::FAILED, 'offline', 'On vous rappelle'));
    }

    public function test_other_delivery_events_are_not_concerned(): void
    {
        $this->mirrorService->shouldNotReceive('postNote');

        $this->assertTrue($this->service->handleDeliveryEvent('picha-chatwoot-70-900', SmsMessageStatus::DELIVERED, null, null));
        $this->assertFalse($this->service->handleDeliveryEvent('picha-sms-5', SmsMessageStatus::FAILED, 'offline', null));
        $this->assertFalse($this->service->handleDeliveryEvent(null, SmsMessageStatus::FAILED, 'offline', null));
    }

    private function expectNumber(): void
    {
        $this->unattributedRepository->shouldReceive('findWhere')->andReturn(collect([$this->unattributed()]));
        $this->settingsRepository->shouldReceive('findFirstWhere')->with(['id' => 5])->andReturn(
            (new OrganizerSmsSettingDomainObject)->setId(5)->setOrganizerId(1)->setApiKey('key')->setFromNumber('+262639000000'),
        );
    }

    private function unattributed(): SmsUnattributedMessageDomainObject
    {
        return (new SmsUnattributedMessageDomainObject)
            ->setId(20)
            ->setLineSettingsId(5)
            ->setPhone('+262639111111')
            ->setContent('Salut')
            ->setChatwootConversationId(70);
    }
}
