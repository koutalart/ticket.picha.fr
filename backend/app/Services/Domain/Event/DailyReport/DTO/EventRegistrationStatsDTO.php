<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Event\DailyReport\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class EventRegistrationStatsDTO extends BaseDataObject
{
    public function __construct(
        public readonly int $totalRegistrations,
        public readonly int $todayRegistrations,
        public readonly float $revenue,
        public readonly int $openCarts,
    ) {}
}
