<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Event\DailyReport\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class EventDailyReportDTO extends BaseDataObject
{
    public function __construct(
        public readonly int $eventId,
        public readonly int $organizerId,
        public readonly string $eventTitle,
        public readonly string $currency,
        public readonly string $localDateTime,
        public readonly string $timezone,
        public readonly EventRegistrationStatsDTO $stats,
        public readonly string $text,
    ) {}
}
