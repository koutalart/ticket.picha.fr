<?php

declare(strict_types=1);

namespace Tests\Unit\Ticket;

use HiEvents\Services\Domain\Ticket\AttendeeTicketZplService;
use HiEvents\Services\Domain\Ticket\DTO\ZplTicketDataDTO;
use HiEvents\Services\Domain\Ticket\ZplImageConverter;
use Tests\TestCase;

class AttendeeTicketZplServiceTest extends TestCase
{
    private function service(): AttendeeTicketZplService
    {
        return new AttendeeTicketZplService(new ZplImageConverter);
    }

    private function logo(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        imagefilledrectangle($image, 10, 10, $width - 10, $height - 10, imagecolorallocate($image, 0, 0, 0));
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    public function test_zpl_contains_ticket_fields_and_fixed_footer(): void
    {
        app()->setLocale('fr');

        $zpl = $this->service()->generate(new ZplTicketDataDTO(
            publicId: 'A-SFMVW8P',
            eventTitle: 'Triangle des Bermudes',
            productTitle: 'Entrée simple',
            attendeeName: 'Prévente 001',
            eventDate: 'Dim. 11 oct. 2026',
            eventTime: '18h00',
            venue: 'Le 5/5, Mamoudzou',
            sellerName: 'Innocent Event',
        ));

        self::assertStringStartsWith('^XA', $zpl);
        self::assertStringEndsWith('^XZ', trim($zpl));
        self::assertStringContainsString('^PW639', $zpl);
        self::assertStringContainsString('^LL639', $zpl);
        self::assertStringNotContainsString('^MN', $zpl);
        self::assertStringContainsString('^FO366,268^BQN,2,9^FDQA,A-SFMVW8P^FS', $zpl);
        self::assertStringContainsString('S F M V W 8 P', $zpl);
        self::assertStringContainsString('Vendeur : Innocent Event', $zpl);
        self::assertStringContainsString('Prévente 001', $zpl);
        self::assertStringContainsString('É V É N E M E N T', $zpl);
        self::assertStringContainsString('^A0N,66,58^FDTriangle des Bermudes^FS', $zpl);
        self::assertStringContainsString('Entrée simple', $zpl);
        self::assertStringContainsString('Dim. 11 oct. 2026', $zpl);
        self::assertStringContainsString('18h00', $zpl);
        self::assertStringContainsString('Le 5/5, Mamoudzou', $zpl);
        self::assertStringContainsString('^FO36,540^GB567,2,2^FS', $zpl);
        self::assertStringContainsString("Plateforme de gestion d'événements", $zpl);
        self::assertStringContainsString('ticket.picha.fr', $zpl);
        self::assertStringContainsString('^FO44,549^GFA,2328,2328,24,', $zpl);
        self::assertStringContainsString('^FO292,592^GFA,', $zpl);
    }

    public function test_header_uses_organizer_logo_and_sponsor_logo(): void
    {
        app()->setLocale('fr');

        $zpl = $this->service()->generate(new ZplTicketDataDTO(
            publicId: 'A-SAFE',
            eventTitle: 'Mayotte',
            productTitle: 'PASS',
            organizerLogo: $this->logo(300, 300),
            sponsorLogo: $this->logo(400, 200),
        ));

        self::assertStringContainsString('^FO318,63^GB3,62,3^FS', $zpl);
        self::assertStringContainsString('Sponsor', $zpl);
        self::assertSame(5, substr_count($zpl, '^GFA,'));
    }

    public function test_header_centres_logo_without_sponsor(): void
    {
        $zpl = $this->service()->generate(new ZplTicketDataDTO(
            publicId: 'A-SAFE',
            eventTitle: 'Mayotte',
            productTitle: 'PASS',
            organizerLogo: $this->logo(200, 200),
        ));

        self::assertStringNotContainsString('^GB3,62,3', $zpl);
        self::assertStringContainsString('^FO269,30^GFA,', $zpl);
    }

    public function test_header_falls_back_to_seller_name_without_logo(): void
    {
        $zpl = $this->service()->generate(new ZplTicketDataDTO(
            publicId: 'A-SAFE',
            eventTitle: 'Mayotte',
            productTitle: 'PASS',
            sellerName: 'Picha Live',
        ));

        self::assertStringContainsString('^FO37,52^A0N,40,34^FB565,2,0,C^FDPicha Live\\&^FS', $zpl);
    }

    public function test_long_titles_use_smaller_font(): void
    {
        $zpl = $this->service()->generate(new ZplTicketDataDTO(
            publicId: 'A-SAFE',
            eventTitle: 'Festival international des musiques de Mayotte',
            productTitle: 'PASS',
        ));

        self::assertStringContainsString('^FO37,186^A0N,40,32^FDFestival international des musiques de^FS', $zpl);
    }

    public function test_omits_empty_rows_and_name(): void
    {
        $zpl = $this->service()->generate(new ZplTicketDataDTO(
            publicId: 'a_SAFE',
            eventTitle: 'Mayotte',
            productTitle: 'PASS',
        ));

        self::assertStringContainsString('QA,a_SAFE', $zpl);
        self::assertStringNotContainsString('^FO99,340', $zpl);
        self::assertStringNotContainsString('^FO344,512', $zpl);
        self::assertStringNotContainsString('^FO324,254', $zpl);
    }

    public function test_escapes_control_characters_in_user_text(): void
    {
        $zpl = $this->service()->generate(new ZplTicketDataDTO(
            publicId: 'a_SAFE',
            eventTitle: 'Night^Out',
            productTitle: 'VIP~Pass',
            attendeeName: 'Ann\\e',
        ));

        self::assertStringNotContainsString('Night^Out', $zpl);
        self::assertStringContainsString('Night Out', $zpl);
        self::assertStringContainsString('VIP Pass', $zpl);
        self::assertStringContainsString('Ann e', $zpl);
    }
}
