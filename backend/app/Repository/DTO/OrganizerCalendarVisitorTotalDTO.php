<?php

declare(strict_types=1);

namespace HiEvents\Repository\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class OrganizerCalendarVisitorTotalDTO extends BaseDataObject
{
    public function __construct(
        public readonly ?string $visitorName,
        public readonly int $openings,
        public readonly int $visits,
        public readonly int $devices,
        public readonly string $lastSeenAt,
    ) {}
}
