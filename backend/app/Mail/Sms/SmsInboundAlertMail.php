<?php

declare(strict_types=1);

namespace HiEvents\Mail\Sms;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\SmsConversationDomainObject;
use HiEvents\DomainObjects\SmsMessageDomainObject;
use HiEvents\Mail\BaseMail;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Collection;

/**
 * @uses /backend/resources/views/emails/sms/inbound-alert.blade.php
 */
class SmsInboundAlertMail extends BaseMail
{
    /**
     * @param  Collection<int, SmsMessageDomainObject>  $messages
     */
    public function __construct(
        private readonly OrganizerDomainObject $organizer,
        private readonly SmsConversationDomainObject $conversation,
        private readonly Collection $messages,
    ) {
        parent::__construct();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('New SMS from :contact', ['contact' => $this->contactLabel()]),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.sms.inbound-alert',
            with: [
                'organizerName' => $this->organizer->getName(),
                'contact' => $this->contactLabel(),
                'messages' => $this->messages,
                'unreadCount' => $this->conversation->getUnreadCount(),
                'inboxUrl' => rtrim((string) config('app.frontend_url'), '/').'/manage/organizer/'.$this->organizer->getId().'/sms',
            ],
        );
    }

    private function contactLabel(): string
    {
        $name = $this->conversation->getContactName();

        return $name !== null && $name !== ''
            ? $name.' ('.$this->conversation->getPhone().')'
            : $this->conversation->getPhone();
    }
}
