<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Ticket;

use Barryvdh\DomPDF\Facade\Pdf;
use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\Enums\ImageType;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Helper\Url;
use HiEvents\Repository\Interfaces\ProductRepositoryInterface;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

/**
 * D18 (PICHA_BOX_OFFICE_DECISIONS_REQUIRED.md): extraction of
 * AttendeeTicketMail::generateTicketPdf() so the Box Office ticket.pdf /
 * reprint endpoints can reuse the exact same rendering, without depending
 * on a Mailable.
 */
class AttendeeTicketPdfService
{
    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
    )
    {
    }

    public function generate(
        AttendeeDomainObject     $attendee,
        EventDomainObject        $event,
        EventSettingDomainObject $eventSettings,
        OrganizerDomainObject    $organizer,
    ): string
    {
        $product = $attendee->getProduct()
            ?? $this->productRepository->findById($attendee->getProductId());

        $designSettings = $eventSettings->getTicketDesignSettings();
        if (is_string($designSettings)) {
            $designSettings = json_decode($designSettings, true) ?? [];
        }
        $designSettings ??= [];

        $accentColor = $designSettings['accent_color'] ?? '#6B46C1';
        $footerText = $designSettings['footer_text'] ?? null;
        $dateDisplayMode = $designSettings['date_display_mode'] ?? 'START_DATE_TIME';

        $logoImage = $event->getImages()
            ?->first(fn($image) => $image->getType() === ImageType::TICKET_LOGO->name);
        $logoUrl = $logoImage ? Url::getCdnUrl($logoImage->getPath()) : null;

        // simplesoftwareio/simple-qrcode returns an Illuminate\Support\HtmlString,
        // not a plain string — the original AttendeeTicketMail relied on PHP's
        // implicit __toString() coercion (that file has no strict_types
        // declaration); an explicit cast keeps the identical output under
        // strict_types here.
        $qrCodeBase64 = base64_encode(
            (string)QrCode::format('png')->size(300)->margin(1)->generate($attendee->getPublicId())
        );

        return Pdf::loadView('attendee-ticket-pdf', [
            'attendee' => $attendee,
            'event' => $event,
            'eventSettings' => $eventSettings,
            'organizer' => $organizer,
            'product' => $product,
            'qrCodeBase64' => $qrCodeBase64,
            'accentColor' => $accentColor,
            'footerText' => $footerText,
            'dateDisplayMode' => $dateDisplayMode,
            'logoUrl' => $logoUrl,
        ])->output();
    }
}
