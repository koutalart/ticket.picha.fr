<?php

namespace HiEvents\Mail\Attendee;

use HiEvents\Mail\BaseMail;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * @uses /backend/resources/views/emails/attendee/attendance-access-code.blade.php
 */
class AttendanceAccessCodeMail extends BaseMail
{
    public function __construct(
        private readonly string $eventTitle,
        private readonly string $code,
        private readonly int $validityMinutes,
    ) {
        parent::__construct();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('Your code to manage your attendance: :code', ['code' => $this->code]),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.attendee.attendance-access-code',
            with: [
                'eventTitle' => $this->eventTitle,
                'code' => $this->code,
                'validityMinutes' => $this->validityMinutes,
            ]
        );
    }
}
