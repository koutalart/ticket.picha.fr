<?php

declare(strict_types=1);

namespace HiEvents\Mail\LiveChat;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Mail\BaseMail;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * News of a refund request to the customer, when no SMS can reach them.
 *
 * @uses /backend/resources/views/emails/live-chat/refund-update.blade.php
 */
class LiveChatRefundUpdateMail extends BaseMail
{
    public function __construct(
        private readonly ?EventDomainObject $event,
        private readonly OrganizerDomainObject $organizer,
        private readonly string $reference,
        private readonly string $text,
    ) {
        parent::__construct();

        if ($event !== null) {
            $this->useEventEmailLogo($event, $organizer);
        }
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: $this->organizerSender($this->organizer->getId()),
            subject: __('Your refund request :reference', ['reference' => $this->reference]),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.live-chat.refund-update',
            with: [
                'organizer' => $this->organizer,
                'event' => $this->event,
                'organizerName' => $this->organizer->getName(),
                'text' => $this->text,
            ],
        );
    }
}
