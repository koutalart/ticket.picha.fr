<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Event\DailyReport\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class EventActivityStatsDTO extends BaseDataObject
{
    /**
     * Exchanges hold one text per person who wrote during the day (their messages and the reason a human was needed).
     * They are only read to find the topic and are never sent.
     *
     * @param  array<int, string>  $exchanges
     */
    public function __construct(
        public readonly int $confirmationsSent,
        public readonly array $exchanges,
        public readonly int $pendingRefunds,
    ) {}
}
