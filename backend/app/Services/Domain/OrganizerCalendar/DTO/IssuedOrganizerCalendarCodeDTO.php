<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\OrganizerCalendar\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class IssuedOrganizerCalendarCodeDTO extends BaseDataObject
{
    public function __construct(
        public readonly int $codeId,
        public readonly string $code,
        public readonly int $validitySeconds,
    ) {}
}
