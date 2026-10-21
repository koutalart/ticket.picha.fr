<?php

namespace Tests\Unit\Services\Application\Handlers\Sms;

use HiEvents\DomainObjects\OrganizerSmsSettingDomainObject;
use HiEvents\DomainObjects\SmsMessageDomainObject;
use HiEvents\DomainObjects\Status\SmsMessageStatus;
use HiEvents\Exceptions\SmsWebhookUnauthorizedException;
use HiEvents\Repository\Interfaces\OrganizerSmsSettingRepositoryInterface;
use HiEvents\Services\Application\Handlers\Sms\DTO\HttpSmsWebhookDTO;
use HiEvents\Services\Application\Handlers\Sms\HandleHttpSmsWebhookHandler;
use HiEvents\Services\Domain\Sms\DTO\InboundSmsDTO;
use HiEvents\Services\Domain\Sms\DTO\OutboundSmsEventDTO;
use HiEvents\Services\Domain\Sms\HttpSmsWebhookSignatureVerifier;
use HiEvents\Services\Domain\Sms\SmsAssistantService;
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

    private SmsAssistantService|MockInterface $assistantService;

    private HandleHttpSmsWebhookHandler $handler;

    private OrganizerSmsSettingDomainObject $settings;

    protected function setUp(): void
    {
        parent::setUp();

        $this->settingsService = Mockery::mock(SmsSettingsService::class);
        $this->settingsRepository = Mockery::mock(OrganizerSmsSettingRepositoryInterface::class);
        $this->verifier = Mockery::mock(HttpSmsWebhookSignatureVerifier::class);
        $this->inboundService = Mockery::mock(SmsInboundService::class);
        $this->assistantService = Mockery::mock(SmsAssistantService::class);

        $this->handler = new HandleHttpSmsWebhookHandler(
            $this->settingsService,
            $this->settingsRepository,
            $this->verifier,
            $this->inboundService,
            $this->assistantService,
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
        $this->assistantService->shouldReceive('queueDraftIfEligible')->never();

        $this->handler->handle($this->dto('message.phone.received', $this->receivedData()));
    }

    public function test_recorded_message_is_handed_to_the_assistant(): void
    {
        $this->expectVerified();
        $message = (new SmsMessageDomainObject)->setId(9)->setSmsConversationId(3);

        $this->inboundService->shouldReceive('recordInbound')->once()->andReturn($message);
        $this->assistantService->shouldReceive('queueDraftIfEligible')->once()->with($this->settings, $message);

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
