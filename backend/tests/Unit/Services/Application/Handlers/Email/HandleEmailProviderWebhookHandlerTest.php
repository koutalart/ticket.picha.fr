<?php

namespace Tests\Unit\Services\Application\Handlers\Email;

use HiEvents\DomainObjects\Enums\EmailProviderType;
use HiEvents\DomainObjects\Status\EmailMessageStatus;
use HiEvents\Exceptions\EmailWebhookUnauthorizedException;
use HiEvents\Services\Application\Handlers\Email\HandleEmailProviderWebhookHandler;
use HiEvents\Services\Domain\Email\Inbox\EmailDeliveryStatusService;
use HiEvents\Services\Domain\Email\Inbox\EmailReplyAddressService;
use HiEvents\Services\Domain\Email\Inbox\InboundEmailService;
use HiEvents\Services\Infrastructure\EmailProvider\DTO\EmailProviderEventDTO;
use HiEvents\Services\Infrastructure\EmailProvider\DTO\InboundEmailDTO;
use HiEvents\Services\Infrastructure\EmailProvider\EmailProviderInterface;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class HandleEmailProviderWebhookHandlerTest extends TestCase
{
    private EmailProviderInterface&MockInterface $provider;

    private EmailDeliveryStatusService&MockInterface $statuses;

    private InboundEmailService&MockInterface $inbound;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.email_inbox.reply_domain' => 'in.picha.fr']);

        $this->provider = Mockery::mock(EmailProviderInterface::class);
        $this->statuses = Mockery::mock(EmailDeliveryStatusService::class);
        $this->inbound = Mockery::mock(InboundEmailService::class);
        $this->provider->allows('type')->andReturn(EmailProviderType::RESEND);
    }

    public function test_an_unsigned_webhook_is_rejected_before_anything_is_read(): void
    {
        $this->provider->expects('verifyWebhook')->andThrow(new EmailWebhookUnauthorizedException('bad'));
        $this->provider->expects('parseWebhook')->never();

        $this->expectException(EmailWebhookUnauthorizedException::class);

        $this->handler()->handle('{}', []);
    }

    public function test_a_delivery_event_updates_the_logged_email(): void
    {
        $event = new EmailProviderEventDTO(kind: EmailProviderEventDTO::KIND_STATUS, providerEmailId: 'em_1', status: EmailMessageStatus::DELIVERED);
        $this->provider->expects('verifyWebhook');
        $this->provider->expects('parseWebhook')->andReturn($event);
        $this->statuses->expects('apply')->with($event);
        $this->provider->expects('fetchInboundEmail')->never();

        $this->handler()->handle('{}', []);
    }

    public function test_received_emails_not_sent_to_a_reply_address_are_never_fetched(): void
    {
        $this->provider->expects('verifyWebhook');
        $this->provider->expects('parseWebhook')->andReturn(new EmailProviderEventDTO(
            kind: EmailProviderEventDTO::KIND_INBOUND,
            providerEmailId: 'in_1',
            recipients: ['contact@picha.fr'],
        ));
        $this->provider->expects('fetchInboundEmail')->never();
        $this->inbound->expects('handle')->never();

        $this->handler()->handle('{}', []);
    }

    public function test_a_reply_is_fetched_and_filed(): void
    {
        $address = $this->app->make(EmailReplyAddressService::class)->addressFor(5, 9);
        $email = new InboundEmailDTO('in_1', 'a@example.test', null, [$address], 'Re', 'Hi', null, null, [], [], null);

        $this->provider->expects('verifyWebhook');
        $this->provider->expects('parseWebhook')->andReturn(new EmailProviderEventDTO(
            kind: EmailProviderEventDTO::KIND_INBOUND,
            providerEmailId: 'in_1',
            recipients: [$address],
        ));
        $this->provider->expects('fetchInboundEmail')->with('in_1')->andReturn($email);
        $this->inbound->expects('handle')->with($email, 'RESEND');

        $this->handler()->handle('{}', []);
    }

    private function handler(): HandleEmailProviderWebhookHandler
    {
        return new HandleEmailProviderWebhookHandler(
            $this->provider,
            $this->statuses,
            $this->inbound,
            $this->app->make(EmailReplyAddressService::class),
        );
    }
}
