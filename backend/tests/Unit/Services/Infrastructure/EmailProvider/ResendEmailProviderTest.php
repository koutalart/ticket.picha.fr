<?php

namespace Tests\Unit\Services\Infrastructure\EmailProvider;

use HiEvents\DomainObjects\Status\EmailMessageStatus;
use HiEvents\Exceptions\EmailProviderException;
use HiEvents\Services\Infrastructure\EmailProvider\DTO\EmailProviderEventDTO;
use HiEvents\Services\Infrastructure\EmailProvider\Resend\ResendEmailProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ResendEmailProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.resend.api_key' => 're_test_key',
            'services.resend.base_url' => 'https://resend.test',
        ]);
    }

    public function test_delivery_events_are_mapped_to_statuses(): void
    {
        $event = $this->provider()->parseWebhook(json_encode([
            'type' => 'email.bounced',
            'created_at' => now()->subMinute()->toIso8601String(),
            'data' => ['email_id' => 'em_1', 'bounce' => ['message' => 'Mailbox does not exist']],
        ]));

        $this->assertSame(EmailProviderEventDTO::KIND_STATUS, $event->kind);
        $this->assertSame('em_1', $event->providerEmailId);
        $this->assertSame(EmailMessageStatus::BOUNCED, $event->status);
        $this->assertSame('Mailbox does not exist', $event->errorMessage);

        $clicked = $this->provider()->parseWebhook(json_encode(['type' => 'email.clicked', 'data' => ['email_id' => 'em_1']]));
        $this->assertSame(EmailMessageStatus::OPENED, $clicked->status);
    }

    public function test_unknown_or_malformed_events_are_ignored(): void
    {
        $this->assertNull($this->provider()->parseWebhook('not json'));
        $this->assertNull($this->provider()->parseWebhook(json_encode(['type' => 'contact.created', 'data' => ['email_id' => 'x']])));
        $this->assertNull($this->provider()->parseWebhook(json_encode(['type' => 'email.delivered', 'data' => []])));
    }

    public function test_a_received_email_event_announces_its_recipients(): void
    {
        $event = $this->provider()->parseWebhook(json_encode([
            'type' => 'email.received',
            'data' => [
                'email_id' => 'in_1',
                'to' => ['Organisateur <R-12-ABC@in.picha.fr>'],
                'cc' => [],
                'received_for' => ['other@picha.fr'],
            ],
        ]));

        $this->assertSame(EmailProviderEventDTO::KIND_INBOUND, $event->kind);
        $this->assertSame(['r-12-abc@in.picha.fr', 'other@picha.fr'], $event->recipients);
    }

    public function test_a_received_email_is_fetched_with_its_body_and_reply_headers(): void
    {
        Http::fake([
            'resend.test/emails/receiving/in_1*' => Http::response([
                'id' => 'in_1',
                'from' => 'oyhana@example.test',
                'to' => ['r-12-abc@in.picha.fr'],
                'subject' => 'Re: Votre commande',
                'html' => '<p>Bonjour</p>',
                'text' => 'Bonjour',
                'headers' => [
                    'From' => '"Oyhana Inoussa" <Oyhana@Example.test>',
                    'In-Reply-To' => '<first@picha.fr>',
                    'References' => '<root@picha.fr> <first@picha.fr>',
                ],
                'message_id' => '<reply@example.test>',
                'created_at' => now()->toIso8601String(),
                'attachments' => [['id' => 'a', 'filename' => 'photo.jpg', 'content_type' => 'image/jpeg', 'size' => 1200]],
            ]),
        ]);

        $email = $this->provider()->fetchInboundEmail('in_1');

        $this->assertSame('oyhana@example.test', $email->fromEmail);
        $this->assertSame('Oyhana Inoussa', $email->fromName);
        $this->assertSame(['r-12-abc@in.picha.fr'], $email->recipients);
        $this->assertSame(['<root@picha.fr>', '<first@picha.fr>'], $email->references);
        $this->assertSame([['filename' => 'photo.jpg', 'content_type' => 'image/jpeg', 'size' => 1200]], $email->attachments);

        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer re_test_key')
            && str_contains($request->url(), 'html_format=cid'));
    }

    public function test_a_refused_fetch_raises_a_provider_error(): void
    {
        Http::fake(['resend.test/*' => Http::response(['message' => 'Not found'], 404)]);

        $this->expectException(EmailProviderException::class);

        $this->provider()->fetchInboundEmail('missing');
    }

    private function provider(): ResendEmailProvider
    {
        return $this->app->make(ResendEmailProvider::class);
    }
}
