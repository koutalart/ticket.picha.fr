<?php

declare(strict_types=1);

namespace HiEvents\DomainObjects\Enums;

use HiEvents\DomainObjects\Status\SmsCampaignRecipientStatus;
use HiEvents\DomainObjects\Status\SmsMessageStatus;

/**
 * State of an SMS in the central SMS queue (picha-sms-file).
 */
enum SmsFileState: string
{
    use BaseEnum;

    case QUEUED = 'en_file';
    case SUBMITTING = 'soumission';
    case SUBMITTED = 'soumis';
    case SENT = 'envoye';
    case DELIVERED = 'distribue';
    case EXPIRED = 'expire';
    case FAILED = 'echec';
    case CANCELLED = 'annule';

    public function messageStatus(): SmsMessageStatus
    {
        return match ($this) {
            self::QUEUED, self::SUBMITTING => SmsMessageStatus::QUEUED,
            self::SUBMITTED => SmsMessageStatus::PENDING,
            self::SENT => SmsMessageStatus::SENT,
            self::DELIVERED => SmsMessageStatus::DELIVERED,
            self::EXPIRED => SmsMessageStatus::EXPIRED,
            self::FAILED, self::CANCELLED => SmsMessageStatus::FAILED,
        };
    }

    public function recipientStatus(): SmsCampaignRecipientStatus
    {
        return match ($this) {
            self::QUEUED, self::SUBMITTING, self::SUBMITTED => SmsCampaignRecipientStatus::SCHEDULED,
            self::SENT => SmsCampaignRecipientStatus::SENT,
            self::DELIVERED => SmsCampaignRecipientStatus::DELIVERED,
            self::EXPIRED => SmsCampaignRecipientStatus::EXPIRED,
            self::FAILED, self::CANCELLED => SmsCampaignRecipientStatus::FAILED,
        };
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::DELIVERED, self::EXPIRED, self::FAILED, self::CANCELLED], true);
    }
}
