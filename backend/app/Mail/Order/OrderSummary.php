<?php

namespace HiEvents\Mail\Order;

use Barryvdh\DomPDF\Facade\Pdf;
use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\InvoiceDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Helper\Url;
use HiEvents\Mail\BaseMail;
use HiEvents\Services\Domain\Email\DTO\RenderedEmailTemplateDTO;
use HiEvents\Services\Domain\Event\EventCalendarFileService;
use HiEvents\Services\Domain\Ticket\AttendeeTicketPdfService;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * @uses /backend/resources/views/emails/orders/summary.blade.php
 */
class OrderSummary extends BaseMail
{
    private readonly ?RenderedEmailTemplateDTO $renderedTemplate;

    public function __construct(
        private readonly OrderDomainObject $order,
        private readonly EventDomainObject $event,
        private readonly OrganizerDomainObject $organizer,
        private readonly EventSettingDomainObject $eventSettings,
        private readonly ?InvoiceDomainObject $invoice,
        ?RenderedEmailTemplateDTO $renderedTemplate = null,
        /** @var AttendeeDomainObject[] Tickets attached to this e-mail instead of being sent separately */
        private readonly array $ticketAttendees = [],
    ) {
        $this->renderedTemplate = $renderedTemplate;

        parent::__construct();
    }

    public function envelope(): Envelope
    {
        $subject = $this->renderedTemplate?->subject ?? __('Your Order is Confirmed!').' 🎉';

        return new Envelope(
            replyTo: $this->eventSettings->getSupportEmail(),
            subject: $subject,
        );
    }

    public function content(): Content
    {
        if ($this->renderedTemplate) {
            return new Content(
                markdown: 'emails.custom-template',
                with: [
                    'renderedBody' => $this->renderedTemplate->body,
                    'renderedCta' => $this->renderedTemplate->cta,
                    'eventSettings' => $this->eventSettings,
                ]
            );
        }

        // Fallback to original template
        return new Content(
            markdown: 'emails.orders.summary',
            with: [
                'eventSettings' => $this->eventSettings,
                'event' => $this->event,
                'order' => $this->order,
                'organizer' => $this->organizer,
                'ticketAttendees' => $this->ticketAttendees,
                'orderUrl' => sprintf(
                    Url::getFrontEndUrlFromConfig(Url::ORDER_SUMMARY),
                    $this->event->getId(),
                    $this->order->getShortId(),
                ),
            ]
        );
    }

    public function attachments(): array
    {
        $attachments = [];

        if ($this->invoice !== null) {
            $invoice = Pdf::loadView('invoice', [
                'order' => $this->order,
                'event' => $this->event,
                'organizer' => $this->organizer,
                'eventSettings' => $this->eventSettings,
                'invoice' => $this->invoice,
            ]);

            $attachments[] = Attachment::fromData(
                static fn () => $invoice->output(),
                'invoice.pdf',
            )->withMime('application/pdf');
        }

        if ($this->ticketAttendees === []) {
            return $attachments;
        }

        $calendar = app(EventCalendarFileService::class)->ics(
            $this->event,
            $this->organizer,
            $this->eventSettings,
            'order-'.$this->order->getId(),
        );
        $attachments[] = Attachment::fromData(static fn () => $calendar, 'event.ics')->withMime('text/calendar');

        foreach ($this->ticketAttendees as $attendee) {
            $attachments[] = Attachment::fromData(
                fn () => app(AttendeeTicketPdfService::class)->generate($attendee, $this->event, $this->eventSettings, $this->organizer),
                'ticket-'.$attendee->getShortId().'.pdf',
            )->withMime('application/pdf');
        }

        return $attachments;
    }
}
