<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class SmsReminderRecipientDTO extends BaseDataObject
{
    public function __construct(
        public readonly string $phone,
        public readonly int $attendeeId,
        public readonly ?string $firstName,
        public readonly ?string $contactName,
    ) {}
}
