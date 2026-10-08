<?php

namespace Tests\Unit\Services\Application\Handlers\DemoRequest;

use HiEvents\DomainObjects\Enums\DemoRequestEventType;
use HiEvents\Mail\DemoRequest\DemoRequestEmail;
use HiEvents\Services\Application\Handlers\DemoRequest\DTO\SendDemoRequestDTO;
use HiEvents\Services\Application\Handlers\DemoRequest\SendDemoRequestHandler;
use Illuminate\Config\Repository;
use Illuminate\Mail\Mailer;
use Mockery as m;
use Tests\TestCase;

class SendDemoRequestHandlerTest extends TestCase
{
    private Mailer $mailer;
    private Repository $config;
    private SendDemoRequestHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailer = m::mock(Mailer::class);
        $this->config = m::mock(Repository::class);

        $this->handler = new SendDemoRequestHandler($this->mailer, $this->config);
    }

    public function testSendsDemoRequestToConfiguredAddress(): void
    {
        app()->setLocale('fr');

        $this->config->shouldReceive('get')
            ->with('app.demo_request_email')
            ->andReturn('sales@example.com');

        $this->mailer->shouldReceive('to')
            ->once()
            ->with('sales@example.com')
            ->andReturnSelf();

        $this->mailer->shouldReceive('send')
            ->once()
            ->with(m::on(function (DemoRequestEmail $mail) {
                $envelope = $mail->envelope();
                $content = $mail->content();

                return $envelope->replyTo[0]->address === 'jane.doe@acme.fr'
                    && $envelope->replyTo[0]->name === 'Jane Doe'
                    && $content->with['organization'] === 'ACME'
                    && $content->with['eventType'] === 'Conférence'
                    && $content->with['eventDate'] === 'Novembre 2026'
                    && $content->with['attendeeCount'] === '150 à 500';
            }));

        $this->handler->handle(new SendDemoRequestDTO(
            first_name: 'Jane',
            last_name: 'Doe',
            email: 'jane.doe@acme.fr',
            organization: 'ACME',
            event_type: DemoRequestEventType::CONFERENCE,
            event_date: '2026-11',
            attendee_count: '150-500',
        ));

        $this->assertTrue(true);
    }
}
