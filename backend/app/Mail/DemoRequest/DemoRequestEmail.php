<?php

namespace HiEvents\Mail\DemoRequest;

use HiEvents\DomainObjects\Enums\DemoRequestEventType;
use HiEvents\Mail\BaseMail;
use Illuminate\Support\Carbon;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class DemoRequestEmail extends BaseMail
{
    public function __construct(
        private readonly string               $firstName,
        private readonly string               $lastName,
        private readonly string               $email,
        private readonly string               $organization,
        private readonly DemoRequestEventType $eventType,
        private readonly ?string              $eventDate,
        private readonly string               $attendeeCount,
    )
    {
        parent::__construct();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            replyTo: [new Address($this->email, $this->firstName . ' ' . $this->lastName)],
            subject: __('Demo request: :organization', ['organization' => $this->organization]),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.demo-request.demo-request',
            with: [
                'fullName' => $this->firstName . ' ' . $this->lastName,
                'email' => $this->email,
                'organization' => $this->organization,
                'eventType' => $this->eventType->label(),
                'eventDate' => $this->formatEventDate(),
                'attendeeCount' => $this->attendeeCountLabel(),
            ],
        );
    }

    private function formatEventDate(): ?string
    {
        if (!$this->eventDate) {
            return null;
        }

        return ucfirst(Carbon::createFromFormat('!Y-m', $this->eventDate)
            ->locale(app()->getLocale())
            ->translatedFormat('F Y'));
    }

    private function attendeeCountLabel(): string
    {
        return match ($this->attendeeCount) {
            '<50' => __('Fewer than 50'),
            '50-150' => __('50 to 150'),
            '150-500' => __('150 to 500'),
            '500-1000' => __('500 to 1,000'),
            '>1000' => __('More than 1,000'),
            default => $this->attendeeCount,
        };
    }
}
