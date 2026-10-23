<?php

namespace Tests\Unit\Services\Domain\Sms\Reminder;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\Helper\SmsSegmentCounter;
use HiEvents\Services\Domain\Sms\Reminder\SmsReminderMessageRenderer;
use Tests\TestCase;

class SmsReminderMessageRendererTest extends TestCase
{
    private SmsReminderMessageRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->renderer = new SmsReminderMessageRenderer;
    }

    public function test_replaces_every_variable_in_the_event_timezone(): void
    {
        $message = $this->renderer->render(
            'Bonjour {prenom}, rappel : {evenement} le {date} à {heure}. Votre lien : {lien}',
            $this->event(),
            'Awa',
            'https://ticket.picha.fr/r/abc',
        );

        $this->assertSame(
            'Bonjour Awa, rappel : Konka Wawa 25 ans le samedi 24 octobre à 20h00. Votre lien : https://ticket.picha.fr/r/abc',
            $message,
        );
    }

    public function test_accepts_accented_and_uppercase_variables(): void
    {
        $message = $this->renderer->render('{Prénom} : {ÉVÉNEMENT}', $this->event(), 'Awa', null);

        $this->assertSame('Awa : Konka Wawa 25 ans', $message);
    }

    public function test_missing_first_name_and_link_leave_no_gap(): void
    {
        $message = $this->renderer->render(
            "Bonjour {prenom}, on vous attend à {heure}.\n{lien}\nA ce soir",
            $this->event(),
            null,
            null,
        );

        $this->assertSame("Bonjour, on vous attend à 20h00.\n\nA ce soir", $message);
    }

    public function test_the_label_of_a_missing_link_is_removed_with_it(): void
    {
        $template = "C'est validé pour {evenement}. {date}, accueil dès {heure}. Gérer ma venue : {lien}";

        $this->assertSame(
            "C'est validé pour Konka Wawa 25 ans. samedi 24 octobre, accueil dès 20h00.",
            $this->renderer->render($template, $this->event(), null, null),
        );
        $this->assertSame(
            "C'est validé pour Konka Wawa 25 ans. samedi 24 octobre, accueil dès 20h00. Gérer ma venue : https://ticket.picha.fr/venue/8/a1",
            $this->renderer->render($template, $this->event(), null, 'https://ticket.picha.fr/venue/8/a1'),
        );
        $this->assertSame(
            "A ce soir !\nMerci",
            $this->renderer->render("A ce soir ! Votre venue : { lien }\nMerci", $this->event(), null, null),
        );
    }

    public function test_unknown_variables_are_kept(): void
    {
        $this->assertSame('Code {code}', $this->renderer->render('Code {code}', $this->event(), 'Awa', null));
    }

    public function test_detects_the_personal_link_variable(): void
    {
        $this->assertTrue($this->renderer->usesPersonalLink('Confirmez ici : { lien }'));
        $this->assertFalse($this->renderer->usesPersonalLink('Bonjour {prenom}'));
    }

    public function test_event_without_start_date_renders_empty_date(): void
    {
        $event = $this->event()->setStartDate(null);

        $this->assertSame('Le à', $this->renderer->render('Le {date} à {heure}', $event, null, null));
    }

    public function test_counts_sms_segments(): void
    {
        $this->assertSame(0, SmsSegmentCounter::count(''));
        $this->assertSame(1, SmsSegmentCounter::count(str_repeat('a', 160)));
        $this->assertSame(2, SmsSegmentCounter::count(str_repeat('a', 161)));
        $this->assertSame(1, SmsSegmentCounter::count('Rappel : à ce soir, é è ù'));
        $this->assertSame(2, SmsSegmentCounter::count(str_repeat('ê', 71)));
        $this->assertSame(2, SmsSegmentCounter::count(str_repeat('€', 81)));
    }

    private function event(): EventDomainObject
    {
        return (new EventDomainObject)
            ->setId(8)
            ->setTitle('Konka Wawa 25 ans')
            ->setStartDate('2026-10-24 17:00:00')
            ->setTimezone('Indian/Mayotte');
    }
}
