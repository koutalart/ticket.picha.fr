<?php

declare(strict_types=1);

namespace Tests\Unit\Ticket;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Mail\Attendee\AttendeeTicketMail;
use HiEvents\Models\Attendee;
use HiEvents\Models\EventSetting;
use HiEvents\Models\Order;
use HiEvents\Models\Organizer as OrganizerModel;
use HiEvents\Services\Domain\Ticket\AttendeeTicketPdfService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Smalot\PdfParser\Parser as PdfParser;
use Tests\Support\BoxOfficeTestFixtures;
use Tests\TestCase;

/**
 * TDD (red) tests for AttendeeTicketPdfService, the extraction of
 * AttendeeTicketMail::generateTicketPdf() planned for the Kiosk (D18,
 * PICHA_BOX_OFFICE_DESIGN_OPTIONS.md — "réutiliser la maquette
 * attendee-ticket-pdf.blade.php, extraite en service"). The service does
 * not exist yet.
 *
 * DomPDF compresses its content streams (FlateDecode) by default, so a raw
 * substring search on the PDF bytes never matches — confirmed empirically
 * against the current AttendeeTicketMail output. These tests extract real
 * text via smalot/pdfparser (require-dev only) instead of comparing bytes,
 * and check the QR by its presence as an embedded 300×300 image XObject
 * (matching QrCode::format('png')->size(300) in AttendeeTicketMail today),
 * not by decoding its payload.
 */
class AttendeeTicketPdfServiceTest extends TestCase
{
    use DatabaseTransactions;
    use BoxOfficeTestFixtures;

    private function extractText(string $pdf): string
    {
        return (new PdfParser())->parseContent($pdf)->getText();
    }

    /**
     * @return array{width: int|string, height: int|string}[]
     */
    private function extractImages(string $pdf): array
    {
        $document = (new PdfParser())->parseContent($pdf);

        // getObjectsByType() keys results by internal PDF object id, not a
        // 0-based index — array_values() gives predictable [0], [1]... access.
        return array_values(array_map(
            static fn($image) => $image->getDetails(),
            $document->getObjectsByType('XObject', 'Image'),
        ));
    }

    /**
     * @return array{0: AttendeeDomainObject, 1: EventDomainObject, 2: EventSettingDomainObject, 3: OrganizerDomainObject, 4: OrderDomainObject}
     */
    private function buildDomainObjects(string $firstName, string $lastName): array
    {
        [$event, $product, $productPrice, $user] = $this->createEventWithProduct(price: 25.00);

        $order = Order::create([
            'event_id' => $event->id,
            'total_gross' => 25.00,
            'currency' => 'USD',
            'status' => 'COMPLETED',
            'short_id' => \HiEvents\Helper\IdHelper::shortId(\HiEvents\Helper\IdHelper::ORDER_PREFIX),
            'public_id' => \HiEvents\Helper\IdHelper::publicId(\HiEvents\Helper\IdHelper::ORDER_PREFIX),
        ]);

        $attendeeModel = Attendee::create([
            'event_id' => $event->id,
            'product_id' => $product->id,
            'product_price_id' => $productPrice->id,
            'order_id' => $order->id,
            'status' => 'ACTIVE',
            'email' => 'jane@example.test',
            'first_name' => $firstName,
            'last_name' => $lastName,
            'short_id' => \HiEvents\Helper\IdHelper::shortId(\HiEvents\Helper\IdHelper::ATTENDEE_PREFIX),
            'public_id' => \HiEvents\Helper\IdHelper::publicId(\HiEvents\Helper\IdHelper::ATTENDEE_PREFIX),
        ]);

        $eventSettingModel = EventSetting::where('event_id', $event->id)->first();
        $organizerModel = OrganizerModel::find($event->organizer_id);

        return [
            AttendeeDomainObject::hydrateFromModel($attendeeModel),
            EventDomainObject::hydrateFromModel($event),
            EventSettingDomainObject::hydrateFromModel($eventSettingModel),
            OrganizerDomainObject::hydrateFromModel($organizerModel),
            OrderDomainObject::hydrateFromModel($order),
        ];
    }

    /** AC-15, AC-17 */
    public function test_pdf_contains_public_id_and_qr_encodes_public_id(): void
    {
        [$attendee, $event, $eventSettings, $organizer] = $this->buildDomainObjects('Jane', 'Doe');

        $service = app(AttendeeTicketPdfService::class);
        $pdf = $service->generate($attendee, $event, $eventSettings, $organizer);

        self::assertNotEmpty($pdf);
        self::assertStringContainsString(
            implode(' ', str_split(explode('-', $attendee->getPublicId(), 2)[1])),
            $this->extractText($pdf),
            'AC-17: the public_id (without its A- prefix, letter-spaced as on the design) must appear in clear text',
        );

        self::assertTrue(
            $this->hasQrImage($this->extractImages($pdf)),
            'AC-15: the 300×300 QR image must be embedded on the ticket',
        );
    }

    public function test_pdf_follows_picha_ticket_design(): void
    {
        app()->setLocale('fr');
        [$attendee, $event, $eventSettings, $organizer] = $this->buildDomainObjects('Anli', 'Madi');
        $attendee->setLocale('fr');
        $eventSettings->setTicketSponsorName('Bé digital');

        $pdf = app(AttendeeTicketPdfService::class)->generate($attendee, $event, $eventSettings, $organizer);
        $text = $this->extractText($pdf);

        self::assertStringContainsString('É V É N E M E N T', $text);
        self::assertStringContainsString('Test Event', $text);
        self::assertStringContainsString("T Y P E   D ' E N T R É E", $text);
        self::assertStringContainsString('Anli Madi', $text);
        self::assertStringContainsString(implode(' ', str_split(explode('-', $attendee->getPublicId(), 2)[1])), $text);
        self::assertStringContainsString('Sponsor', $text);
        self::assertStringContainsString('Bé digital', $text);
        self::assertStringContainsString("Billetterie et gestion d'événement", $text);
        self::assertStringContainsString('ticket.picha.fr', $text);
        self::assertStringContainsString('Ticket', $text);
        self::assertGreaterThanOrEqual(2, count($this->extractImages($pdf)), 'QR + PICHA AI logo');
    }

    /**
     * @param array<int, array<string, mixed>> $images
     */
    private function hasQrImage(array $images): bool
    {
        foreach ($images as $image) {
            if ((string) $image['Width'] === '300' && (string) $image['Height'] === '300') {
                return true;
            }
        }

        return false;
    }

    public function test_pdf_is_rendered_in_attendee_locale_and_restores_app_locale(): void
    {
        [$attendee, $event, $eventSettings, $organizer] = $this->buildDomainObjects('Amina', 'Test');
        $attendee->setLocale('fr');
        app()->setLocale('en');

        $pdf = app(AttendeeTicketPdfService::class)->generate($attendee, $event, $eventSettings, $organizer);

        $text = $this->extractText($pdf);
        self::assertStringContainsString("T Y P E   D ' E N T R É E", $text);
        self::assertStringContainsString("Billetterie et gestion d'événement", $text);
        self::assertStringNotContainsString('T I C K E T   T Y P E', $text);
        self::assertSame('en', app()->getLocale());
    }

    /** AC-19 */
    public function test_pdf_renders_french_accents(): void
    {
        [$attendee, $event, $eventSettings, $organizer] = $this->buildDomainObjects('François', 'Ébène');

        $service = app(AttendeeTicketPdfService::class);
        $pdf = $service->generate($attendee, $event, $eventSettings, $organizer);

        self::assertNotEmpty($pdf, 'AC-19: PDF must render without a gd/font error for accented names');

        $text = $this->extractText($pdf);
        self::assertStringContainsString('François', $text);
        self::assertStringContainsString('Ébène', $text);
    }

    /**
     * AC-18: extracting the PDF generation into a service must not change
     * what is rendered on the native confirmation mail's ticket. Compared
     * by extracted text and QR presence, not raw bytes — DomPDF is not
     * byte-for-byte deterministic across two separate renders (see class
     * docblock).
     */
    public function test_extracted_service_produces_same_output_as_mail_attachment(): void
    {
        [$attendee, $event, $eventSettings, $organizer, $order] = $this->buildDomainObjects('Jane', 'Doe');

        $mail = new AttendeeTicketMail($order, $attendee, $event, $eventSettings, $organizer);
        $reflection = new \ReflectionMethod($mail, 'generateTicketPdf');
        $reflection->setAccessible(true);
        $mailPdf = $reflection->invoke($mail);

        $service = app(AttendeeTicketPdfService::class);
        $servicePdf = $service->generate($attendee, $event, $eventSettings, $organizer);

        self::assertSame($this->extractText($mailPdf), $this->extractText($servicePdf));

        $mailImages = $this->extractImages($mailPdf);
        $serviceImages = $this->extractImages($servicePdf);
        self::assertCount(count($mailImages), $serviceImages);
        self::assertTrue($this->hasQrImage($mailImages));
        self::assertTrue($this->hasQrImage($serviceImages));
    }
}
