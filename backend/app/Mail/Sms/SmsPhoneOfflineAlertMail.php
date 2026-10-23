<?php

declare(strict_types=1);

namespace HiEvents\Mail\Sms;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\OrganizerSmsSettingDomainObject;
use HiEvents\Mail\BaseMail;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * @uses /backend/resources/views/emails/sms/phone-offline-alert.blade.php
 */
class SmsPhoneOfflineAlertMail extends BaseMail
{
    public function __construct(
        private readonly OrganizerDomainObject $organizer,
        private readonly OrganizerSmsSettingDomainObject $settings,
    ) {
        parent::__construct();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('Your SMS phone is offline'),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.sms.phone-offline-alert',
            with: [
                'organizerName' => $this->organizer->getName(),
                'phoneNumber' => $this->settings->getFromNumber(),
                'offlineSince' => $this->formatOfflineSince(),
                'settingsUrl' => rtrim((string) config('app.frontend_url'), '/').'/manage/organizer/'.$this->organizer->getId().'/settings#sms',
            ],
        );
    }

    private function formatOfflineSince(): ?string
    {
        $changedAt = $this->settings->getPhoneStatusChangedAt();
        if ($changedAt === null) {
            return null;
        }

        try {
            return Carbon::parse($changedAt, 'UTC')
                ->setTimezone($this->organizer->getTimezone())
                ->format('d/m/Y H:i');
        } catch (Throwable) {
            return null;
        }
    }
}
