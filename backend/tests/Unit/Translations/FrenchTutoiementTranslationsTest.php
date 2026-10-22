<?php

declare(strict_types=1);

namespace Tests\Unit\Translations;

use HiEvents\Helper\CustomerLocale;
use HiEvents\Helper\EmailFormatHelper;
use Tests\TestCase;

class FrenchTutoiementTranslationsTest extends TestCase
{
    /**
     * @return array<string, string>
     */
    private function catalog(string $locale): array
    {
        return json_decode((string) file_get_contents(lang_path("$locale.json")), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_tu_lines_replace_the_french_ones(): void
    {
        self::assertSame('Ta commande a été annulée', __('Your order has been cancelled', [], CustomerLocale::FRENCH_TUTOIEMENT));
        self::assertSame('Votre commande a été annulée', __('Your order has been cancelled', [], 'fr'));
    }

    public function test_lines_without_tu_variant_fall_back_to_french_not_english(): void
    {
        self::assertSame('Bonjour', __('Hello', [], CustomerLocale::FRENCH_TUTOIEMENT));
        self::assertSame('Voir l\'événement', __('View Event', [], CustomerLocale::FRENCH_TUTOIEMENT));
    }

    public function test_plurals_follow_french_rules(): void
    {
        app()->setLocale(CustomerLocale::FRENCH_TUTOIEMENT);

        self::assertSame('Ton billet', trans_choice('Your ticket|Your tickets', 1));
        self::assertSame('Tes billets', trans_choice('Your ticket|Your tickets', 2));
    }

    public function test_dates_and_amounts_are_formatted_in_french(): void
    {
        app()->setLocale(CustomerLocale::FRENCH_TUTOIEMENT);

        self::assertSame('45,00 €', str_replace(["\u{a0}", "\u{202f}"], ' ', EmailFormatHelper::money(45, 'EUR')));
        self::assertSame('samedi 31 octobre 2026 à 18h00', EmailFormatHelper::dateTime('2026-10-31 15:00:00', 'Indian/Mayotte'));
    }

    public function test_the_tu_catalog_only_holds_french_lines_written_in_tu(): void
    {
        $french = $this->catalog('fr');
        $tu = $this->catalog(CustomerLocale::FRENCH_TUTOIEMENT);

        self::assertNotEmpty($tu);
        foreach ($tu as $source => $translation) {
            self::assertArrayHasKey($source, $french, "Ligne absente de fr.json : $source");
            self::assertNotSame($french[$source], $translation, "Ligne identique à fr.json : $source");
            self::assertDoesNotMatchRegularExpression('/\b(vous|votre|vos|veuillez)\b/iu', str_replace('Rendez-vous', '', $translation), $source);
            self::assertDoesNotMatchRegularExpression('/[•·—–]/u', $translation, $source);
        }
    }
}
