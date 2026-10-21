<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class SmsReminderPreviewDTO extends BaseDataObject
{
    public function __construct(
        public readonly int $recipients,
        public readonly int $smsCount,
        public readonly ?string $sampleMessage,
        public readonly int $activeAttendees,
        public readonly int $withoutValidPhone,
        public readonly int $optedOut,
        public readonly int $sharedPhone,
        public readonly bool $personalLinkAvailable,
        public readonly string $eventTimezone,
        public readonly ?string $scheduledAt,
    ) {}
}
