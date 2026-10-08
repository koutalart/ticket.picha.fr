<?php

declare(strict_types=1);

namespace Tests\Unit\Ticket;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\Helper\IdHelper;
use HiEvents\Models\Attendee;
use HiEvents\Models\Event;
use HiEvents\Models\EventSetting;
use HiEvents\Models\Order;
use HiEvents\Services\Domain\Ticket\DTO\TicketDataDTO;
use HiEvents\Services\Domain\Ticket\TicketDataFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Support\BoxOfficeTestFixtures;
use Tests\TestCase;

class AttendeeTicketPdfTemplateTest extends TestCase
{
    use BoxOfficeTestFixtures;
    use DatabaseTransactions;

    private function ticket(?array $locationDetails = null, string $email = 'amina@example.invalid', string $status = 'ACTIVE'): TicketDataDTO
    {
        [$event, $product, $productPrice] = $this->createEventWithProduct();
        Event::whereKey($event->id)->update([
            'title' => 'Concert Mamoudzou',
            'start_date' => '2026-09-28 16:00:00',
            'timezone' => 'Indian/Mayotte',
            'location_details' => null,
        ]);
        EventSetting::where('event_id', $event->id)->update(['location_details' => $locationDetails]);
        $product->update(['title' => '🎟️ Entrée simple']);

        $order = Order::create([
            'event_id' => $event->id,
            'total_gross' => 25.00,
            'currency' => 'USD',
            'status' => 'COMPLETED',
            'short_id' => IdHelper::shortId(IdHelper::ORDER_PREFIX),
            'public_id' => IdHelper::publicId(IdHelper::ORDER_PREFIX),
        ]);

        $attendee = Attendee::create([
            'event_id' => $event->id,
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_price_id' => $productPrice->id,
            'status' => $status,
            'email' => $email,
            'first_name' => 'Amina',
            'last_name' => 'Test',
            'short_id' => IdHelper::shortId(IdHelper::ATTENDEE_PREFIX),
            'public_id' => IdHelper::publicId(IdHelper::ATTENDEE_PREFIX),
        ]);

        app()->setLocale('fr');

        return app(TicketDataFactory::class)->forAttendee(AttendeeDomainObject::hydrateFromModel($attendee));
    }

    private function render(TicketDataDTO $ticket): string
    {
        return view('attendee-ticket-pdf', ['ticket' => $ticket, 'ticketImageBase64' => ''])->render();
    }

    public function test_start_date_is_shown_in_event_timezone_not_utc(): void
    {
        $ticket = $this->ticket();

        self::assertSame('Lun. 28 sept. 2026', $ticket->eventDate);
        self::assertSame('19h00', $ticket->eventTime);
    }

    public function test_labels_are_translated_in_french(): void
    {
        $html = $this->render($this->ticket());

        self::assertStringContainsString('Date et heure', $html);
        self::assertStringContainsString('N° de billet', $html);
        self::assertStringNotContainsString('Date &amp; Time', $html);
    }

    public function test_product_title_has_no_emoji(): void
    {
        self::assertSame('Entrée simple', $this->ticket()->productTitle);
    }

    public function test_venue_is_empty_when_address_is_empty(): void
    {
        $ticket = $this->ticket(locationDetails: ['venue_name' => null, 'city' => null, 'country' => null]);

        self::assertSame('', $ticket->venue);
    }

    public function test_venue_is_shown_when_address_is_set(): void
    {
        $ticket = $this->ticket(locationDetails: ['venue_name' => 'Stade de Cavani', 'city' => 'Mamoudzou', 'country' => 'YT']);

        self::assertSame('Stade de Cavani, Mamoudzou', $ticket->venue);
        self::assertStringContainsString('Stade de Cavani', $this->render($ticket));
    }

    public function test_email_is_never_printed(): void
    {
        self::assertStringNotContainsString('no-mail.picha.invalid', $this->render($this->ticket(email: 'kiosk.s1620@no-mail.picha.invalid')));
        self::assertStringNotContainsString('amina@example.invalid', $this->render($this->ticket()));
    }

    public function test_cancelled_attendee_gets_a_cancelled_ticket(): void
    {
        self::assertSame(TicketDataDTO::STATUS_CANCELLED, $this->ticket(status: 'CANCELLED')->status);
    }
}
