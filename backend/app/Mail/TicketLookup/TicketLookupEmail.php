<?php

namespace HiEvents\Mail\TicketLookup;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Helper\Url;
use HiEvents\Mail\BaseMail;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Sent in the organizer's name when every order belongs to the same organizer, in the platform's name otherwise.
 *
 * @uses /backend/resources/views/emails/ticket-lookup/ticket-lookup.blade.php
 */
class TicketLookupEmail extends BaseMail
{
    public function __construct(
        private readonly string $email,
        private readonly string $token,
        private readonly int $orderCount,
        private readonly ?string $publicBaseUrl = null,
        private readonly ?OrganizerDomainObject $organizer = null,
        private readonly ?string $firstName = null,
    ) {
        parent::__construct();
        $this->shortLinkOrganizer = $organizer;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: $this->organizerSender($this->organizer?->getId()),
            subject: __('Your Tickets'),
        );
    }

    public function content(): Content
    {
        $organizerName = trim((string) $this->organizer?->getName());
        $firstName = trim((string) $this->firstName);

        return new Content(
            markdown: 'emails.ticket-lookup.ticket-lookup',
            with: [
                'email' => $this->email,
                'orderCount' => $this->orderCount,
                'logoLinkUrl' => $this->publicBaseUrl,
                'firstName' => $firstName !== '' ? $firstName : null,
                'organizerName' => $organizerName !== '' ? $organizerName : config('app.name'),
                'ticketLookupUrl' => sprintf(
                    Url::getFrontEndUrlFromConfig(Url::TICKET_LOOKUP, baseUrl: $this->publicBaseUrl),
                    $this->token,
                ),
            ]
        );
    }
}
