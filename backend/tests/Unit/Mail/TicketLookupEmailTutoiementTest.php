<?php

namespace Tests\Unit\Mail;

use HiEvents\Helper\CustomerLocale;
use HiEvents\Mail\TicketLookup\TicketLookupEmail;
use Tests\TestCase;

class TicketLookupEmailTutoiementTest extends TestCase
{
    private const VOUS = '/\b(vous|votre|vos)\b/iu';

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.customer_locale' => 'fr', 'app.frontend_url' => 'https://ticket.picha.fr']);
    }

    public function test_the_lookup_email_says_tu_in_french_tutoiement(): void
    {
        [$subject, $text] = $this->render(CustomerLocale::FRENCH_TUTOIEMENT);

        self::assertSame('Tes billets', $subject);
        self::assertStringContainsString('Clique sur le bouton ci-dessous pour voir tes billets et le détail de ta commande.', $text);
        self::assertDoesNotMatchRegularExpression(self::VOUS, $text.' '.$subject);
    }

    public function test_the_lookup_email_keeps_vous_otherwise(): void
    {
        [$subject, $text] = $this->render('fr');

        self::assertSame('Vos billets', $subject);
        self::assertStringContainsString('Cliquez sur le bouton ci-dessous pour voir vos billets', $text);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function render(string $locale): array
    {
        $mail = (new TicketLookupEmail('client@exemple.test', 'tl_token', 2))->locale($locale);

        $html = preg_replace('#<(style|head)[^>]*>.*?</\1>#si', '', $mail->render());
        $text = preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES));

        return [$mail->withLocale($locale, fn () => $mail->envelope()->subject), $text];
    }
}
