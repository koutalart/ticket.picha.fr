<?php

declare(strict_types=1);

namespace HiEvents\Mail\Sms;

use HiEvents\DomainObjects\Enums\SmsWatchdogAnomalyType;
use HiEvents\Mail\BaseMail;
use HiEvents\Services\Domain\Sms\DTO\SmsWatchdogAnomalyDTO;
use HiEvents\Services\Domain\Sms\Watchdog\SmsWatchdogDetector;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * @uses /backend/resources/views/emails/sms/watchdog-alert.blade.php
 */
class SmsWatchdogAlertMail extends BaseMail
{
    public const TIMEZONE = 'Indian/Mayotte';

    public const MAX_LINES = 50;

    /**
     * @param  array<int, SmsWatchdogAnomalyDTO>  $anomalies
     */
    public function __construct(
        private readonly array $anomalies,
    ) {
        parent::__construct();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('SMS watchdog: :count anomaly(ies)', ['count' => count($this->anomalies)]),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.sms.watchdog-alert',
            with: [
                'lines' => array_map(fn (SmsWatchdogAnomalyDTO $anomaly) => $this->line($anomaly), array_slice($this->anomalies, 0, self::MAX_LINES)),
                'hiddenCount' => max(0, count($this->anomalies) - self::MAX_LINES),
            ],
        );
    }

    public static function maskPhone(?string $phone): string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';
        if ($digits === '') {
            return '?';
        }

        if (strlen($digits) <= 4) {
            return str_repeat('*', strlen($digits));
        }

        return str_repeat('*', strlen($digits) - 4).substr($digits, -4);
    }

    public static function localTime(?string $utc): string
    {
        if ($utc === null || $utc === '') {
            return '?';
        }

        try {
            return Carbon::parse($utc, 'UTC')->setTimezone(self::TIMEZONE)->format('d/m/Y H:i');
        } catch (Throwable) {
            return '?';
        }
    }

    /**
     * @return array{type: string, organizer: string, conversation: string, phone: string, time: string, detail: string, excerpt: ?string}
     */
    private function line(SmsWatchdogAnomalyDTO $anomaly): array
    {
        return [
            'type' => $anomaly->type->label(),
            'organizer' => $anomaly->organizerName.' (#'.$anomaly->organizerId.')',
            'conversation' => $anomaly->conversationId !== null ? '#'.$anomaly->conversationId : '',
            'phone' => self::maskPhone($anomaly->phone),
            'time' => self::localTime($anomaly->occurredAt),
            'detail' => $this->detail($anomaly),
            'excerpt' => $anomaly->excerpt,
        ];
    }

    private function detail(SmsWatchdogAnomalyDTO $anomaly): string
    {
        return match ($anomaly->type) {
            SmsWatchdogAnomalyType::UNANSWERED_INBOUND => __('No reply for more than :minutes minutes', ['minutes' => SmsWatchdogDetector::REPLY_DELAY_MINUTES]),
            SmsWatchdogAnomalyType::DUPLICATE_REPLY => __(':count replies to the same SMS', ['count' => (int) $anomaly->count]),
            SmsWatchdogAnomalyType::DUPLICATE_REGISTRATION_CONFIRMATION => __('Second confirmation sent for event #:event', ['event' => (int) $anomaly->eventId]),
            SmsWatchdogAnomalyType::STUCK_PENDING => __('Still pending after :minutes minutes', ['minutes' => SmsWatchdogDetector::PENDING_MINUTES]),
            SmsWatchdogAnomalyType::FAILED_OUTBOUND => __('Sending failed'),
        };
    }
}
