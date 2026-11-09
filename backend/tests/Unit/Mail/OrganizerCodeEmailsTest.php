<?php

declare(strict_types=1);

namespace Tests\Unit\Mail;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Mail\Attendee\AttendanceAccessCodeMail;
use HiEvents\Mail\TicketLookup\TicketLookupEmail;
use HiEvents\Services\Domain\Mail\EmailLogoResolver;
use HiEvents\Services\Domain\Mail\OrganizerMailSenderResolver;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Mockery as m;
use Tests\Support\FakesPublicBaseUrl;
use Tests\TestCase;

/**
 * The « Gérer ma venue » code and the ticket lookup e-mails are sent and signed in the organizer's name.
 */
class OrganizerCodeEmailsTest extends TestCase
{
    use FakesPublicBaseUrl;

    private const EVENT_TITLE = 'Le Concert des Générations';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.name' => 'PICHA Ticket',
            'app.customer_locale' => 'fr',
            'app.frontend_url' => 'https://ticket.picha.fr',
            'mail.from.address' => 'ticket@picha.fr',
        ]);

        $this->fakePublicBaseUrl([], [14 => 8]);

        $logoResolver = m::mock(EmailLogoResolver::class);
        $logoResolver->shouldReceive('resolveForEvent')->andReturnNull();
        $this->app->instance(EmailLogoResolver::class, $logoResolver);

        $senderResolver = m::mock(OrganizerMailSenderResolver::class);
        $senderResolver->shouldReceive('resolveForOrganizer')->andReturnUsing(
            fn (?int $organizerId) => $organizerId === 8 ? new Address('ticket@picha.fr', "Kultur'm") : null
        );
        $this->app->instance(OrganizerMailSenderResolver::class, $senderResolver);
    }

    private function organizer(): OrganizerDomainObject
    {
        return (new OrganizerDomainObject)->setId(8)->setName("Kultur'm");
    }

    private function event(): EventDomainObject
    {
        return (new EventDomainObject)
            ->setId(14)
            ->setOrganizerId(8)
            ->setTitle(self::EVENT_TITLE)
            ->setTimezone('Indian/Mayotte');
    }

    private function accessCodeMail(?string $firstName = 'Élodie', bool $withOrganizer = true): AttendanceAccessCodeMail
    {
        return new AttendanceAccessCodeMail(
            eventTitle: self::EVENT_TITLE,
            code: '482913',
            validityMinutes: 15,
            event: $this->event(),
            organizer: $withOrganizer ? $this->organizer() : null,
            firstName: $firstName,
        );
    }

    private function visibleText(Mailable $mail): string
    {
        $html = preg_replace('#<(style|head)[^>]*>.*?</\1>#si', '', $mail->render());

        return preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5));
    }

    public function test_access_code_shows_the_code_without_raw_html(): void
    {
        $mail = $this->accessCodeMail()->locale('fr');
        $html = $mail->render();
        $text = $this->visibleText($mail);

        self::assertMatchesRegularExpression('#<div[^>]*>\s*482913\s*</div>#', $html);
        self::assertStringNotContainsString('<pre', $html);
        self::assertStringNotContainsString('<code', $html);
        self::assertStringNotContainsString('</td>', $text);
        self::assertStringNotContainsString('</tr>', $text);
        self::assertStringNotContainsString('<div', $text);
        self::assertStringContainsString('482913', $text);
    }

    public function test_access_code_greets_by_first_name_and_names_the_event_correctly(): void
    {
        $text = $this->visibleText($this->accessCodeMail()->locale('fr'));

        self::assertStringContainsString('Bonjour Élodie,', $text);
        self::assertStringContainsString('Voici votre code pour Le Concert des Générations :', $text);
        self::assertStringNotContainsString('à Le Concert', $text);
        self::assertDoesNotMatchRegularExpression('/[•·—–]/u', $text);
    }

    public function test_access_code_without_first_name_says_hello_only(): void
    {
        $text = $this->visibleText($this->accessCodeMail(firstName: null)->locale('fr'));

        self::assertStringContainsString('Gérer ma venue Bonjour, Voici votre code', $text);
    }

    public function test_access_code_is_sent_and_signed_by_the_organizer(): void
    {
        $mail = $this->accessCodeMail()->locale('fr');
        $text = $this->visibleText($mail);

        self::assertSame("Kultur'm", $mail->envelope()->from?->name);
        self::assertStringContainsString("Au plaisir de vous accueillir prochainement ! Kultur'm", $text);
        self::assertStringNotContainsString('Merci', $text);
    }

    public function test_access_code_falls_back_on_the_event_organizer_for_the_sender(): void
    {
        $mail = $this->accessCodeMail(withOrganizer: false);

        self::assertSame("Kultur'm", $mail->envelope()->from?->name);
    }

    public function test_access_code_says_tu_for_organizers_in_tu(): void
    {
        $text = $this->visibleText($this->accessCodeMail()->locale('fr_TU'));

        self::assertStringContainsString('Voici ton code pour Le Concert des Générations :', $text);
    }

    public function test_ticket_lookup_is_sent_and_signed_by_the_organizer_and_greets_by_first_name(): void
    {
        $mail = (new TicketLookupEmail('elodie@exemple.test', 'tl_token', 2, null, $this->organizer(), 'Élodie'))->locale('fr');
        $html = $mail->render();
        $text = $this->visibleText($mail);

        self::assertSame("Kultur'm", $mail->envelope()->from?->name);
        self::assertStringContainsString('Bonjour Élodie,', $text);
        self::assertStringContainsString("Au plaisir de vous accueillir prochainement ! Kultur'm", $text);
        self::assertStringNotContainsString('</td>', $text);
        self::assertStringNotContainsString('<pre', $html);
        self::assertStringNotContainsString('Merci', $text);
    }

    public function test_ticket_lookup_for_several_organizers_is_signed_by_the_platform(): void
    {
        $mail = (new TicketLookupEmail('client@exemple.test', 'tl_token', 2))->locale('fr');
        $text = $this->visibleText($mail);

        self::assertNull($mail->envelope()->from);
        self::assertStringContainsString('Vos billets Bonjour, Nous avons trouvé', $text);
        self::assertStringContainsString('Au plaisir de vous accueillir prochainement ! PICHA Ticket', $text);
    }

    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }
}
