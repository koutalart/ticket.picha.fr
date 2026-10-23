<?php

declare(strict_types=1);

namespace HiEvents\DomainObjects\Enums;

enum SmsWatchdogAnomalyType: string
{
    use BaseEnum;

    case UNANSWERED_INBOUND = 'UNANSWERED_INBOUND';
    case DRAFT_PENDING = 'DRAFT_PENDING';
    case DUPLICATE_REPLY = 'DUPLICATE_REPLY';
    case DUPLICATE_REGISTRATION_CONFIRMATION = 'DUPLICATE_REGISTRATION_CONFIRMATION';
    case STUCK_PENDING = 'STUCK_PENDING';
    case FAILED_OUTBOUND = 'FAILED_OUTBOUND';

    public function label(): string
    {
        return match ($this) {
            self::UNANSWERED_INBOUND => __('SMS without reply'),
            self::DRAFT_PENDING => __('Draft waiting for validation'),
            self::DUPLICATE_REPLY => __('Duplicate reply'),
            self::DUPLICATE_REGISTRATION_CONFIRMATION => __('Duplicate registration confirmation'),
            self::STUCK_PENDING => __('SMS stuck pending'),
            self::FAILED_OUTBOUND => __('SMS not delivered'),
        };
    }
}
