<?php

namespace HiEvents\Mail\Attendee;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\Enums\ImageType;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\ImageDomainObject;
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
use Illuminate\Support\Str;

/**
 * @uses /backend/resources/views/emails/orders/attendee-ticket.blade.php
 */
class AttendeeTicketMail extends BaseMail
{
    private readonly ?RenderedEmailTemplateDTO $renderedTemplate;

    public function __construct(
        private readonly OrderDomainObject $order,
        private readonly AttendeeDomainObject $attendee,
        private readonly EventDomainObject $event,
        private readonly EventSettingDomainObject $eventSettings,
        private readonly OrganizerDomainObject $organizer,
        ?RenderedEmailTemplateDTO $renderedTemplate = null,
    ) {
        parent::__construct();
        $this->renderedTemplate = $renderedTemplate;
    }

    public function envelope(): Envelope
    {
        $subject = $this->renderedTemplate?->subject ?? __('🎟️ Your Ticket for :event', [
            'event' => Str::limit($this->event->getTitle(), 50),
        ]);

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
                    'organizer' => $this->organizer,
                    'organizerLogoUrl' => $this->isTargetFreeEvent() ? $this->getOrganizerLogoUrl() : null,
                    'isFreeEvent' => $this->isFreeEvent(),
                ]
            );
        }

        // If no template is provided, use the default blade template
        return new Content(
            markdown: 'emails.orders.attendee-ticket',
            with: [
                'event' => $this->event,
                'attendee' => $this->attendee,
                'eventSettings' => $this->eventSettings,
                'organizer' => $this->organizer,
                'organizerLogoUrl' => $this->isFreeEvent() ? $this->getOrganizerLogoUrl() : null,
                'isFreeEvent' => $this->isFreeEvent(),
                'order' => $this->order,
                'ticketUrl' => sprintf(
                    Url::getFrontEndUrlFromConfig(Url::ATTENDEE_TICKET),
                    $this->event->getId(),
                    $this->attendee->getShortId(),
                ),
            ]
        );
    }

    public function attachments(): array
    {
        $calendar = app(EventCalendarFileService::class)->ics(
            $this->event,
            $this->organizer,
            $this->eventSettings,
            'event-'.$this->attendee->getId(),
        );

        return [
            Attachment::fromData(static fn () => $calendar, 'event.ics')
                ->withMime('text/calendar'),
            Attachment::fromData(
                fn () => $this->generateTicketPdf(),
                'ticket-'.$this->attendee->getShortId().'.pdf',
            )->withMime('application/pdf'),
        ];
    }

    private function isFreeEvent(): bool
    {
        $ticketItems = $this->order->getTicketOrderItems();

        return $ticketItems->isNotEmpty()
            && $ticketItems->every(static fn ($item) => (float) $item->getPrice() <= 0);
    }

    private function isTargetFreeEvent(): bool
    {
        return $this->isFreeEvent() && (int) $this->event->getId() === 6;
    }

    private function getOrganizerLogoUrl(): ?string
    {
        $logo = $this->organizer->getImages()
            ?->first(static fn (ImageDomainObject $image) => $image->getType() === ImageType::ORGANIZER_LOGO->name);

        return $logo?->getPath() ? Url::getCdnUrl($logo->getPath()) : null;
    }

    private function generateTicketPdf(): string
    {
        return app(AttendeeTicketPdfService::class)->generate(
            $this->attendee,
            $this->event,
            $this->eventSettings,
            $this->organizer,
        );
    }
}
