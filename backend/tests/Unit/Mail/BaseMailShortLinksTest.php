<?php

namespace Tests\Unit\Mail;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\Mail\BaseMail;
use HiEvents\Services\Domain\PublicUrl\PublicBaseUrlResolver;
use HiEvents\Services\Domain\Sms\SmsShortLinkService;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Tests\TestCase;

class BaseMailShortLinksTest extends TestCase
{
    public function test_a_customer_e_mail_attached_to_an_event_carries_no_long_link(): void
    {
        config(['app.frontend_url' => 'https://ticket.picha.fr', 'mail.default' => 'array']);

        $resolver = Mockery::mock(PublicBaseUrlResolver::class);
        $resolver->shouldReceive('platformUrl')->andReturn('https://ticket.picha.fr');
        $resolver->shouldReceive('forOrganizerId')->with(8)->andReturn('https://mayottela1ere.fr');
        $resolver->shouldReceive('forEvent')->andReturn('https://mayottela1ere.fr');
        $this->app->instance(PublicBaseUrlResolver::class, $resolver);

        $shortLinks = Mockery::mock(SmsShortLinkService::class);
        $shortLinks->shouldReceive('shortenFor')
            ->with(3, 8, 14, 'Le Concert des Générations', 'https://ticket.picha.fr/product/14/a_AD72w9Ryh6Xgj')
            ->once()
            ->andReturn('https://ticket.picha.fr/q/k2m9x4p');
        $this->app->instance(SmsShortLinkService::class, $shortLinks);

        $event = (new EventDomainObject)
            ->setId(14)
            ->setAccountId(3)
            ->setOrganizerId(8)
            ->setTitle('Le Concert des Générations');

        $mail = new class($event) extends BaseMail
        {
            public function __construct(private readonly EventDomainObject $event)
            {
                parent::__construct();
                $this->emailLogoEvent = $this->event;
            }

            public function envelope(): Envelope
            {
                return new Envelope(subject: 'Votre invitation');
            }

            public function content(): Content
            {
                return new Content(htmlString: '<p>Voir mon billet: <a href="https://ticket.picha.fr/product/14/a_AD72w9Ryh6Xgj">https://ticket.picha.fr/product/14/a_AD72w9Ryh6Xgj</a></p>');
            }
        };

        $mail->to('participant@example.com')->send(Mail::mailer('array'));

        $sent = Mail::mailer('array')->getSymfonyTransport()->messages()->last()->getOriginalMessage();
        $this->assertStringNotContainsString('/product/14/', $sent->getHtmlBody());
        $this->assertStringContainsString('https://ticket.picha.fr/q/k2m9x4p', $sent->getHtmlBody());
    }
}
