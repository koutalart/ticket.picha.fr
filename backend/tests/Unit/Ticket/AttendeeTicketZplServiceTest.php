<?php

declare(strict_types=1);

namespace Tests\Unit\Ticket;

use HiEvents\Services\Domain\Ticket\AttendeeTicketZplService;
use HiEvents\Services\Domain\Ticket\DTO\ZplLabelFormatDTO;
use Tests\TestCase;

class AttendeeTicketZplServiceTest extends TestCase
{
    public function test_zpl_contains_qr_payload_and_ticket_fields(): void
    {
        app()->setLocale('fr');

        $zpl = (new AttendeeTicketZplService)->generate(
            publicId: 'A-SFMVW8P',
            eventTitle: 'Triangle des bermudes',
            productTitle: 'Entrée simple',
            attendeeName: 'Jane Doe',
            eventWhen: 'DIM 11/10/26',
            priceLabel: '25 €',
            organizerName: 'Picha Live',
            organizerPhone: '06 39 12 34 56',
            eventHours: '16h00 - 02h30',
            venueName: 'Le 5/5',
            venueCity: 'Mamoudzou',
            labelFormat: new ZplLabelFormatDTO(width_mm: 80.0, length_mm: 101.0),
        );

        self::assertStringStartsWith('^XA', $zpl);
        self::assertStringEndsWith('^XZ', trim($zpl));
        self::assertStringContainsString('^PW639', $zpl);
        self::assertStringContainsString('^FO365,280^BQN,2,10', $zpl);
        self::assertStringContainsString('QA,A-SFMVW8P', $zpl);
        self::assertStringContainsString('A - SFMVW8P', $zpl);
        self::assertStringContainsString('Triangle des bermudes', $zpl);
        self::assertStringContainsString('Entrée simple', $zpl);
        self::assertStringContainsString('25 €', $zpl);
        self::assertStringContainsString('DIM 11/10/26', $zpl);
        self::assertStringContainsString('16h00 - 02h30', $zpl);
        self::assertStringContainsString('Le 5/5', $zpl);
        self::assertStringContainsString('Mamoudzou', $zpl);
        self::assertStringContainsString('TYPE D\'ENTRÉE', $zpl);
        self::assertStringContainsString('HEURE', $zpl);
        self::assertStringContainsString('Picha Live', $zpl);
        self::assertStringContainsString('06 39 12 34 56', $zpl);
        self::assertStringContainsString('06 39 78 07 73', $zpl);
        self::assertStringContainsString('Votre prochain événement ?', $zpl);
        self::assertStringContainsString('PICHA Ticket s\'en occupe.', $zpl);
        self::assertStringContainsString('picha.fr', $zpl);
        self::assertStringContainsString('^GFA,2328,2328,24,', $zpl);
        self::assertStringContainsString('^FO38,16^GFA,', $zpl);
        self::assertStringContainsString('^FO320,18^GB2,100,2', $zpl);
        self::assertStringContainsString('^FO345,570^A0N,26,26^FB240,2,4,C^FDJane Doe\\&^FS', $zpl);
        self::assertStringNotContainsString('Billet certifié', $zpl);
        self::assertStringNotContainsString('XXXXX', $zpl);
    }

    public function test_zpl_omits_optional_rows_when_empty(): void
    {
        app()->setLocale('fr');

        $zpl = (new AttendeeTicketZplService)->generate(
            publicId: 'a_SAFE',
            eventTitle: 'Mayotte',
            productTitle: 'PASS',
            attendeeName: '',
        );

        self::assertStringNotContainsString('HEURE', $zpl);
        self::assertStringNotContainsString('^FO345,570', $zpl);
        self::assertStringContainsString('QA,a_SAFE', $zpl);
    }

    public function test_zpl_escapes_control_characters_in_user_text(): void
    {
        $zpl = (new AttendeeTicketZplService)->generate(
            publicId: 'a_SAFE',
            eventTitle: 'Night^Out',
            productTitle: 'VIP~Pass',
            attendeeName: 'Ann\\e',
        );

        self::assertStringNotContainsString('Night^Out', $zpl);
        self::assertStringContainsString('^FDAnn e\\&^FS', $zpl);
        self::assertStringContainsString('Night Out', $zpl);
        self::assertStringContainsString('VIP Pass', $zpl);
        self::assertStringContainsString('QA,a_SAFE', $zpl);
    }

    public function test_zpl_uses_custom_sponsor_name(): void
    {
        $zpl = (new AttendeeTicketZplService)->generate(
            publicId: 'a_SAFE',
            eventTitle: 'Mayotte',
            productTitle: 'PASS',
            attendeeName: 'Jane',
            sponsorName: 'Digital',
        );

        self::assertStringContainsString('Digital', $zpl);
        self::assertStringNotContainsString('XXXXX', $zpl);
    }

    public function test_zpl_omits_placeholder_sponsor(): void
    {
        $zpl = (new AttendeeTicketZplService)->generate(
            publicId: 'a_SAFE',
            eventTitle: 'Mayotte',
            productTitle: 'PASS',
            attendeeName: 'Jane',
            sponsorName: 'XXXXX',
        );

        self::assertStringNotContainsString('XXXXX', $zpl);
        self::assertStringNotContainsString('SPONSORISÉ PAR', $zpl);
    }

    public function test_attendee_name_is_truncated_to_two_lines(): void
    {
        $zpl = (new AttendeeTicketZplService)->generate(
            publicId: 'a_SAFE',
            eventTitle: 'Mayotte',
            productTitle: 'PASS',
            attendeeName: str_repeat('A', 60),
        );

        self::assertStringContainsString('^FD'.str_repeat('A', 40).'\\&^FS', $zpl);
        self::assertStringNotContainsString(str_repeat('A', 41), $zpl);
    }

    public function test_venue_is_shortened_to_left_column_when_name_is_printed(): void
    {
        $withName = (new AttendeeTicketZplService)->generate(
            publicId: 'a_SAFE',
            eventTitle: 'Mayotte',
            productTitle: 'PASS',
            attendeeName: 'Jane',
            venueName: 'Salle polyvalente de Cavani',
            labelFormat: new ZplLabelFormatDTO(width_mm: 80.0, length_mm: 101.0),
        );
        $withoutName = (new AttendeeTicketZplService)->generate(
            publicId: 'a_SAFE',
            eventTitle: 'Mayotte',
            productTitle: 'PASS',
            attendeeName: '',
            venueName: 'Salle polyvalente de Cavani',
        );

        self::assertStringContainsString('^FO104,566^A0N,34,34^FDSalle polyvale^FS', $withName);
        self::assertStringContainsString('^FDSalle polyvalente de Cavani^FS', $withoutName);
    }
}
