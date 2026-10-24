<?php

declare(strict_types=1);

namespace HiEvents\Mail\LiveChat;

use HiEvents\DomainObjects\LiveChatMessageDomainObject;
use HiEvents\DomainObjects\LiveChatSessionDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Mail\BaseMail;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Collection;

/**
 * A visitor of the web chat left an e-mail address (the organizer replies from the mailbox, Reply-To is the visitor),
 * or a phone number while the organizer has no SMS line to answer with.
 *
 * @uses /backend/resources/views/emails/live-chat/contact-alert.blade.php
 */
class LiveChatContactAlertMail extends BaseMail
{
    /**
     * @param  Collection<int, LiveChatMessageDomainObject>  $transcript
     */
    public function __construct(
        private readonly OrganizerDomainObject $organizer,
        private readonly LiveChatSessionDomainObject $session,
        private readonly Collection $transcript,
    ) {
        parent::__construct();
    }

    public function envelope(): Envelope
    {
        $email = $this->session->getVisitorEmail();

        return new Envelope(
            replyTo: $email !== null ? [new Address($email)] : [],
            subject: __('Web chat: :email is waiting for your answer', ['email' => $email ?? $this->session->getVisitorPhone()]),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.live-chat.contact-alert',
            with: [
                'organizerName' => $this->organizer->getName(),
                'email' => $this->session->getVisitorEmail(),
                'phone' => $this->session->getVisitorPhone(),
                'reason' => $this->session->getFollowUpReason(),
                'transcript' => $this->transcript,
                'chatsUrl' => rtrim((string) config('app.frontend_url'), '/').'/manage/organizer/'.$this->organizer->getId().'/sms',
            ],
        );
    }
}
