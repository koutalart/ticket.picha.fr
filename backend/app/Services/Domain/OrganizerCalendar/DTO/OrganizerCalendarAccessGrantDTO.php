<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\OrganizerCalendar\DTO;

use Carbon\CarbonInterface;
use HiEvents\DataTransferObjects\BaseDataObject;

class OrganizerCalendarAccessGrantDTO extends BaseDataObject
{
    public function __construct(
        public readonly int $accessId,
        public readonly string $accessKey,
        public readonly CarbonInterface $expiresAt,
    ) {}
}
