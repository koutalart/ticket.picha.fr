<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Event\DailyReport\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class EventDailyActivityDTO extends BaseDataObject
{
    /**
     * @param  array<string, int>  $topics  number of people per topic
     * @param  array<int, string>  $nextSteps
     */
    public function __construct(
        public readonly int $eventId,
        public readonly EventActivityStatsDTO $stats,
        public readonly array $topics,
        public readonly array $nextSteps,
        public readonly string $text,
    ) {}
}
