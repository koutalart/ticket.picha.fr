<?php

declare(strict_types=1);

namespace HiEvents\Mail\Inbox;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Mail\BaseMail;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

/**
 * @uses /backend/resources/views/emails/inbox/direct-message.blade.php
 */
class OrganizerDirectEmail extends BaseMail
{
    /**
     * @param  array<int, string>  $references  Message-IDs of the conversation, the last one being answered
     */
    public function __construct(
        private readonly OrganizerDomainObject $organizer,
        private readonly string $emailSubject,
        private readonly string $bodyHtml,
        private readonly ?string $replyToAddress,
        private readonly array $references = [],
    ) {
        parent::__construct();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: $this->organizerSender($this->organizer->getId()),
            replyTo: $this->replyToAddress !== null ? [new Address($this->replyToAddress, $this->organizer->getName())] : [],
            subject: $this->emailSubject,
        );
    }

    public function headers(): Headers
    {
        $references = array_map(fn (string $id) => trim($id, '<> '), $this->references);
        $last = $references === [] ? null : $references[array_key_last($references)];

        return new Headers(
            references: $references,
            text: $last !== null ? ['In-Reply-To' => '<'.$last.'>'] : [],
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.inbox.direct-message',
            with: [
                'organizer' => $this->organizer,
                'bodyHtml' => $this->bodyHtml,
            ],
        );
    }
}
