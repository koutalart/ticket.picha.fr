<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class SmsReminderRequestDTO extends BaseDataObject
{
    /**
     * @param  string|null  $sendAt  local date and time in the event timezone, Y-m-d H:i
     */
    public function __construct(
        public readonly int $organizerId,
        public readonly int $eventId,
        public readonly string $message,
        public readonly ?string $sendAt = null,
        public readonly ?string $name = null,
        public readonly ?int $userId = null,
    ) {}
}
