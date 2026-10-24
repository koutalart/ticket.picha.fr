<?php

declare(strict_types=1);

namespace HiEvents\Mail\LiveChat;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Helper\Url;
use HiEvents\Mail\BaseMail;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The code that proves, in the web chat, that the visitor owns the e-mail of the order to refund.
 * Only ever sent to the e-mail of the order.
 *
 * @uses /backend/resources/views/emails/live-chat/refund-code.blade.php
 */
class LiveChatRefundCodeMail extends BaseMail
{
    public function __construct(
        private readonly EventDomainObject $event,
        private readonly OrganizerDomainObject $organizer,
        private readonly string $code,
        private readonly int $validityMinutes,
    ) {
        parent::__construct();

        $this->useEventEmailLogo($event, $organizer);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: $this->organizerSender($this->organizer->getId()),
            subject: __('Your code to cancel your order: :code', ['code' => $this->code]),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.live-chat.refund-code',
            with: [
                'event' => $this->event,
                'organizerName' => $this->organizer->getName(),
                'eventTitle' => $this->event->getTitle(),
                'code' => $this->code,
                'validityMinutes' => $this->validityMinutes,
                'eventUrl' => sprintf(
                    Url::getPublicFrontEndUrlFromConfig(Url::EVENT_HOMEPAGE, $this->event),
                    $this->event->getId(),
                    $this->event->getSlug(),
                ),
            ],
        );
    }
}
