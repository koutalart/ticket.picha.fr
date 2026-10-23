<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class SmsReminderAudienceDTO extends BaseDataObject
{
    /**
     * @param  SmsReminderRecipientDTO[]  $recipients  one per phone number
     */
    public function __construct(
        public readonly array $recipients,
        public readonly int $activeAttendees,
        public readonly int $withoutValidPhone,
        public readonly int $optedOut,
        public readonly int $sharedPhone,
    ) {}
}
