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
 * The PDF is the same picture as the Zebra label and the on-screen ticket, on an 80 × 80 mm page,
 * with the ticket details underneath as a text layer so they stay searchable.
 */
class AttendeeTicketPdfService
{
    private const PAGE_SIZE_POINTS = 226.77;

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

            return Pdf::loadView('attendee-ticket-pdf', [
                'ticket' => $ticket,
                'ticketImageBase64' => base64_encode($this->ticketImageService->png($ticket)),
            ])
                ->setPaper([0, 0, self::PAGE_SIZE_POINTS, self::PAGE_SIZE_POINTS])
                ->output();
        } finally {
            app()->setLocale($previousLocale);
        }
    }
}
