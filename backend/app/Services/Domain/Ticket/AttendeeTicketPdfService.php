<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Ticket;

use Barryvdh\DomPDF\Facade\Pdf;
use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Helper\CustomerLocale;

/**
 * The PDF is the same picture as the on-screen ticket (the Zebra label on an 80 × 80 mm page, or the
 * event invitation card 148 mm wide), with the ticket details underneath as a text layer so they stay searchable.
 */
class AttendeeTicketPdfService
{
    private const LABEL_PAGE_WIDTH_MM = 80;

    private const CARD_PAGE_WIDTH_MM = 148;

    private const POINTS_PER_MM = 72 / 25.4;

    public function __construct(
        private readonly TicketDataFactory $ticketDataFactory,
        private readonly TicketImageService $ticketImageService,
    ) {}

    public function generate(
        AttendeeDomainObject $attendee,
        EventDomainObject $event,
        EventSettingDomainObject $eventSettings,
        OrganizerDomainObject $organizer,
    ): string {
        $previousLocale = app()->getLocale();
        app()->setLocale(CustomerLocale::get());

        try {
            $ticket = $this->ticketDataFactory->forAttendee($attendee);
            $image = $this->ticketImageService->image($ticket);
            $pageWidth = $image->isCard ? self::CARD_PAGE_WIDTH_MM : self::LABEL_PAGE_WIDTH_MM;
            $pageHeight = round($pageWidth * $image->height / max(1, $image->width), 2);

            return Pdf::loadView('attendee-ticket-pdf', [
                'ticket' => $ticket,
                'ticketImageBase64' => base64_encode($image->content),
                'ticketImageMime' => $image->mimeType,
                'pageWidthMm' => $pageWidth,
                'pageHeightMm' => $pageHeight,
            ])
                ->setPaper([0, 0, $pageWidth * self::POINTS_PER_MM, $pageHeight * self::POINTS_PER_MM])
                ->output();
        } finally {
            app()->setLocale($previousLocale);
        }
    }
}
