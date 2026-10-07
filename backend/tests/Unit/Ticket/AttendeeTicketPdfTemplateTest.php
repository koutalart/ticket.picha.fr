<?php

declare(strict_types=1);

namespace Tests\Unit\Ticket;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\Services\Domain\Ticket\AttendeeTicketPdfService;
use Tests\TestCase;

class AttendeeTicketPdfTemplateTest extends TestCase
{
    private function render(?array $locationDetails = null, string $locale = 'fr', string $email = 'amina@example.invalid'): string
    {
        $event = (new EventDomainObject)
            ->setId(1)
            ->setTitle('Concert Mamoudzou')
            ->setStartDate('2026-09-28 16:00:00')
            ->setTimezone('Indian/Mayotte');

        $settings = (new EventSettingDomainObject)->setLocationDetails($locationDetails);

        $organizer = (new OrganizerDomainObject)->setName('Organisateur');

        $attendee = (new AttendeeDomainObject)
            ->setFirstName('Amina')
            ->setLastName('Test')
            ->setEmail($email)
            ->setPublicId('A-TEST123')
            ->setLocale($locale)
            ->setProduct((new ProductDomainObject)->setTitle('Pass VIP'));

        return app(AttendeeTicketPdfService::class)->html($attendee, $event, $settings, $organizer);
    }

    public function test_start_time_is_shown_in_event_timezone_not_utc(): void
    {
        $html = $this->render();

        self::assertStringContainsString('Lun. 28 sept. 2026', $html);
        self::assertStringContainsString('19h00', $html);
        self::assertStringNotContainsString('16h00', $html);
    }

    public function test_labels_are_translated_in_french(): void
    {
        $html = $this->render();

        self::assertStringContainsString('É V É N E M E N T', $html);
        self::assertStringContainsString('T Y P E   D &#039; E N T R É E', $html);
        self::assertStringContainsString('H E U R E', $html);
        self::assertStringContainsString('Billetterie et gestion d&#039;événement', $html);
        self::assertStringNotContainsString('T I C K E T   T Y P E', $html);
    }

    public function test_location_row_is_hidden_when_address_is_empty(): void
    {
        $html = $this->render(locationDetails: ['venue_name' => null, 'city' => null, 'country' => null]);

        self::assertStringNotContainsString('L I E U', $html);
    }

    public function test_location_row_is_shown_when_address_is_set(): void
    {
        $html = $this->render(locationDetails: ['venue_name' => 'Stade de Cavani', 'city' => 'Mamoudzou', 'country' => 'YT']);

        self::assertStringContainsString('L I E U', $html);
        self::assertStringContainsString('Stade de Cavani, Mamoudzou', $html);
    }

    public function test_kiosk_sentinel_email_is_never_printed(): void
    {
        $html = $this->render(email: 'kiosk.s1620@no-mail.picha.invalid');

        self::assertStringNotContainsString('no-mail.picha.invalid', $html);
    }

    public function test_ticket_shows_attendee_name_but_not_email_per_picha_design(): void
    {
        $html = $this->render();

        self::assertStringContainsString('Amina Test', $html);
        self::assertStringNotContainsString('amina@example.invalid', $html);
    }

    public function test_ticket_follows_attendee_locale(): void
    {
        $html = $this->render(locale: 'en');

        self::assertStringContainsString('Mon 28 Sep 2026', $html);
        self::assertStringContainsString('T I C K E T   T Y P E', $html);
    }
}
