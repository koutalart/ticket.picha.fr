<?php

namespace HiEvents\Mail\Attendee;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
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
        private readonly ?EventDomainObject $event = null,
        private readonly ?OrganizerDomainObject $organizer = null,
        private readonly ?string $firstName = null,
    ) {
        parent::__construct();

        if ($event !== null) {
            $this->useEventEmailLogo($event, $organizer ?? $event->getOrganizer());
        }
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: $this->organizerSender($this->organizer?->getId() ?? $this->event?->getOrganizerId()),
            replyTo: array_filter([$this->event?->getEventSettings()?->getSupportEmail()]),
            subject: __('Your code to manage your attendance: :code', ['code' => $this->code]),
        );
    }

    public function content(): Content
    {
        $organizerName = trim((string) ($this->organizer ?? $this->event?->getOrganizer())?->getName());
        $firstName = trim((string) $this->firstName);

        return new Content(
            markdown: 'emails.attendee.attendance-access-code',
            with: [
                'eventTitle' => $this->eventTitle,
                'code' => $this->code,
                'validityMinutes' => $this->validityMinutes,
                'event' => $this->event,
                'firstName' => $firstName !== '' ? $firstName : null,
                'organizerName' => $organizerName !== '' ? $organizerName : config('app.name'),
            ]
        );
    }
}
