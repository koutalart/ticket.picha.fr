<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\OrganizerCalendar\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class OrganizerCalendarCodeSentDTO extends BaseDataObject
{
    public function __construct(
        public readonly string $maskedPhone,
        public readonly string $phoneLastDigits,
        public readonly int $validitySeconds,
    ) {}
}
