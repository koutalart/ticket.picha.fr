<?php

namespace Tests\Unit\Services\Application\Handlers\Sms;

use HiEvents\DomainObjects\OrganizerSmsSettingDomainObject;
use HiEvents\DomainObjects\Status\SmsCampaignRecipientStatus;
use HiEvents\DomainObjects\SmsMessageDomainObject;
use HiEvents\DomainObjects\Status\SmsMessageStatus;
use HiEvents\DomainObjects\Status\SmsPhoneStatus;
use HiEvents\Exceptions\SmsWebhookUnauthorizedException;
use HiEvents\Repository\Interfaces\OrganizerSmsSettingRepositoryInterface;
use HiEvents\Services\Application\Handlers\Sms\DTO\HttpSmsWebhookDTO;
use HiEvents\Services\Application\Handlers\Sms\HandleHttpSmsWebhookHandler;
use HiEvents\Services\Domain\Sms\DTO\InboundSmsDTO;
use HiEvents\Services\Domain\Sms\DTO\OutboundSmsEventDTO;
use HiEvents\Services\Domain\Sms\HttpSmsWebhookSignatureVerifier;
use HiEvents\Services\Domain\Sms\SmsCampaignDeliveryService;
use HiEvents\Services\Domain\Sms\SmsAlertService;
use HiEvents\Services\Domain\Sms\SmsInboundService;
use HiEvents\Services\Domain\Sms\SmsSettingsService;
use Mockery;
use Mockery\MockInterface;
use Psr\Log\NullLogger;
use Tests\TestCase;

class HandleHttpSmsWebhookHandlerTest extends TestCase
{
    private SmsSettingsService|MockInterface $settingsService;

    private OrganizerSmsSettingRepositoryInterface|MockInterface $settingsRepository;

    private HttpSmsWebhookSignatureVerifier|MockInterface $verifier;

    private SmsInboundService|MockInterface $inboundService;

    private SmsCampaignDeliveryService|MockInterface $campaignDeliveryService;
    private SmsAlertService|MockInterface $alertService;

    private HandleHttpSmsWebhookHandler $handler;

    private OrganizerSmsSettingDomainObject $settings;

    protected function setUp(): void
    {
        parent::setUp();

        $this->settingsService = Mockery::mock(SmsSettingsService::class);
        $this->settingsRepository = Mockery::mock(OrganizerSmsSettingRepositoryInterface::class);
        $this->verifier = Mockery::mock(HttpSmsWebhookSignatureVerifier::class);
        $this->inboundService = Mockery::mock(SmsInboundService::class);
        $this->campaignDeliveryService = Mockery::mock(SmsCampaignDeliveryService::class);
        $this->alertService = Mockery::mock(SmsAlertService::class);
        $this->alertService->shouldReceive('markPhoneActive')->andReturnUsing(fn ($settings) => $settings)->byDefault();

        $this->handler = new HandleHttpSmsWebhookHandler(
            $this->settingsService,
            $this->settingsRepository,
            $this->verifier,
            $this->inboundService,
            $this->campaignDeliveryService,
            $this->alertService,
            new NullLogger,
        );

        $this->settings = (new OrganizerSmsSettingDomainObject)
            ->setId(1)
            ->setOrganizerId(4)
            ->setFromNumber('+262639000000')
            ->setWebhookSigningKey('key');
    }

    public function test_unknown_webhook_token_is_rejected(): void
    {
        $this->settingsService->shouldReceive('findByWebhookToken')->andReturnNull();

        $this->expectException(SmsWebhookUnauthorizedException::class);
        $this->handler->handle($this->dto('message.phone.received', []));
    }

    public function test_invalid_signature_is_rejected_before_any_write(): void
    {
        $this->settingsService->shouldReceive('findByWebhookToken')->andReturn($this->settings);
        $this->verifier->shouldReceive('verify')->andThrow(new SmsWebhookUnauthorizedException);
        $this->settingsRepository->shouldNotReceive('updateFromArray');
        $this->inboundService->shouldNotReceive('recordInbound');

        $this->expectException(SmsWebhookUnauthorizedException::class);
        $this->handler->handle($this->dto('message.phone.received', $this->receivedData()));
    }

    public function test_received_message_is_recorded(): void
    {
        $this->expectVerified();

        $this->inboundService->shouldReceive('recordInbound')
            ->once()
            ->withArgs(fn ($settings, InboundSmsDTO $sms) => $settings === $this->settings
                && $sms->providerMessageId === 'msg-1'
                && $sms->contact === '+262639123456'
                && $sms->content === 'STOP');

        $this->handler->handle($this->dto('message.phone.received', $this->receivedData()));
    }

    public function test_message_for_another_phone_is_ignored(): void
    {
        $this->expectVerified();
        $this->inboundService->shouldNotReceive('recordInbound');

        $this->handler->handle($this->dto('message.phone.received', [
            ...$this->receivedData(),
            'owner' => '+33600000000',
        ]));
    }

    public function test_delivered_event_updates_outbound_status(): void
    {
        $this->expectVerified();

        $this->campaignDeliveryService->shouldReceive('applyProviderStatus')
            ->once()
            ->with(4, 'prov-1', SmsCampaignRecipientStatus::DELIVERED, null, null)
            ->andReturnTrue();

        $this->inboundService->shouldReceive('recordOutboundEvent')
            ->once()
            ->withArgs(fn ($settings, OutboundSmsEventDTO $event) => $event->status === SmsMessageStatus::DELIVERED
                && $event->providerMessageId === 'prov-1'
                && $event->requestId === 'picha-sms-5');

        $this->handler->handle($this->dto('message.phone.delivered', [
            'id' => 'prov-1',
            'contact' => '+262639123456',
            'owner' => '+262639000000',
            'content' => 'Bonjour',
            'request_id' => 'picha-sms-5',
        ]));
    }

    public function test_new_received_message_triggers_inbound_alert(): void
    {
        $this->expectVerified();
        $message = (new SmsMessageDomainObject)->setId(9)->setSmsConversationId(3);

        $this->inboundService->shouldReceive('recordInbound')->once()->andReturn($message);
        $this->alertService->shouldReceive('markPhoneActive')->once()->with($this->settings)->andReturn($this->settings);
        $this->alertService->shouldReceive('notifyInbound')->once()->with($this->settings, $message);

        $this->handler->handle($this->dto('message.phone.received', $this->receivedData()));
    }

    public function test_duplicate_received_message_does_not_trigger_alert(): void
    {
        $this->expectVerified();

        $this->inboundService->shouldReceive('recordInbound')->once()->andReturnNull();
        $this->alertService->shouldNotReceive('notifyInbound');

        $this->handler->handle($this->dto('message.phone.received', $this->receivedData()));
    }

    public function test_heartbeat_offline_records_phone_status(): void
    {
        $this->expectVerified();
        $this->inboundService->shouldNotReceive('recordInbound');
        $this->inboundService->shouldNotReceive('recordOutboundEvent');

        $this->alertService->shouldReceive('recordPhoneStatus')
            ->once()
            ->with($this->settings, SmsPhoneStatus::OFFLINE, '2026-10-21T08:00:00Z');

        $this->handler->handle($this->dto('phone.heartbeat.offline', [
            'phone_id' => 'phone-1',
            'owner' => '+262639000000',
            'timestamp' => '2026-10-21T08:00:00Z',
            'last_heartbeat_timestamp' => '2026-10-21T07:45:00Z',
        ]));
    }

    public function test_heartbeat_online_records_phone_status(): void
    {
        $this->expectVerified();

        $this->alertService->shouldReceive('recordPhoneStatus')
            ->once()
            ->with($this->settings, SmsPhoneStatus::ONLINE, null);

        $this->handler->handle($this->dto('phone.heartbeat.online', [
            'phone_id' => 'phone-1',
            'owner' => '+262639000000',
        ]));
    }

    public function test_heartbeat_for_another_phone_is_ignored(): void
    {
        $this->expectVerified();
        $this->alertService->shouldNotReceive('recordPhoneStatus');

        $this->handler->handle($this->dto('phone.heartbeat.offline', [
            'phone_id' => 'phone-1',
            'owner' => '+33600000000',
        ]));
    }

    private function expectVerified(): void
    {
        $this->settingsService->shouldReceive('findByWebhookToken')->with('token-abc')->andReturn($this->settings);
        $this->verifier->shouldReceive('verify')->with('jwt', 'key')->once();
        $this->settingsRepository->shouldReceive('updateFromArray')->once();
    }

    private function receivedData(): array
    {
        return [
            'message_id' => 'msg-1',
            'contact' => '+262639123456',
            'owner' => '+262639000000',
            'content' => 'STOP',
            'timestamp' => '2026-10-21T08:00:00.000Z',
        ];
    }

    private function dto(string $type, array $data): HttpSmsWebhookDTO
    {
        return new HttpSmsWebhookDTO(
            webhookToken: 'token-abc',
            bearerToken: 'jwt',
            eventType: $type,
            payload: json_encode(['type' => $type, 'data' => $data]),
        );
    }
}
