<?php

declare(strict_types=1);

namespace Tests\Unit\Ticket;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use Tests\TestCase;

class AttendeeTicketPdfTemplateTest extends TestCase
{
    private function render(?array $locationDetails = null, string $locale = 'fr'): string
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
            ->setEmail('amina@example.invalid')
            ->setPublicId('A-TEST123')
            ->setLocale($locale);

        $previousLocale = app()->getLocale();
        app()->setLocale($locale);

        try {
            return view('attendee-ticket-pdf', [
                'attendee' => $attendee,
                'event' => $event,
                'eventSettings' => $settings,
                'organizer' => $organizer,
                'product' => null,
                'qrCodeBase64' => '',
                'accentColor' => '#6B46C1',
                'footerText' => null,
                'dateDisplayMode' => 'START_DATE_TIME',
                'logoUrl' => null,
            ])->render();
        } finally {
            app()->setLocale($previousLocale);
        }
    }

    public function test_start_date_is_shown_in_event_timezone_not_utc(): void
    {
        $html = $this->render();

        self::assertStringContainsString('28/09/2026 19:00', $html);
        self::assertStringNotContainsString('28/09/2026 16:00', $html);
    }

    public function test_labels_are_translated_in_french(): void
    {
        $html = $this->render();

        self::assertStringContainsString('Date et heure', $html);
        self::assertStringContainsString('Organisateur', $html);
        self::assertStringContainsString('Participant', $html);
        self::assertStringNotContainsString('Date &amp; Time', $html);
    }

    public function test_location_row_is_hidden_when_address_is_empty(): void
    {
        $html = $this->render(locationDetails: ['venue_name' => null, 'city' => null, 'country' => null]);

        self::assertStringNotContainsString('Lieu', $html);
        self::assertStringNotContainsString('Location', $html);
    }

    public function test_location_row_is_shown_when_address_is_set(): void
    {
        $html = $this->render(locationDetails: ['venue_name' => 'Stade de Cavani', 'city' => 'Mamoudzou', 'country' => 'YT']);

        self::assertStringContainsString('Lieu', $html);
        self::assertStringContainsString('Stade de Cavani', $html);
    }
}
