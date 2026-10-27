<?php

declare(strict_types=1);

namespace HiEvents\Mail\LiveChat;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Mail\BaseMail;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The organizer's answer to a question asked in the chat, when the visitor left only an e-mail address.
 *
 * @uses /backend/resources/views/emails/live-chat/refund-update.blade.php
 */
class LiveChatAnswerMail extends BaseMail
{
    public function __construct(
        private readonly ?EventDomainObject $event,
        private readonly OrganizerDomainObject $organizer,
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
            subject: __('Answer to your question'),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.live-chat.refund-update',
            with: [
                'organizerName' => $this->organizer->getName(),
                'text' => $this->text,
            ],
        );
    }
}
