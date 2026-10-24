<?php

declare(strict_types=1);

namespace HiEvents\Mail\Sms;

use HiEvents\Mail\BaseMail;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * An alert for the platform supervision: what happened, the useful details, and a link to act.
 *
 * @uses /backend/resources/views/emails/sms/platform-supervision-alert.blade.php
 */
class PlatformSupervisionAlertMail extends BaseMail
{
    /**
     * @param  array<string, string>  $details
     */
    public function __construct(
        private readonly string $alertSubject,
        private readonly string $intro,
        private readonly array $details,
        private readonly ?string $url,
    ) {
        parent::__construct();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->alertSubject);
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.sms.platform-supervision-alert',
            with: [
                'intro' => $this->intro,
                'details' => $this->details,
                'url' => $this->url,
            ],
        );
    }
}
