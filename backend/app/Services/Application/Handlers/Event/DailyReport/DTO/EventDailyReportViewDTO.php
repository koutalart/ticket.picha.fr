<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Event\DailyReport\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class EventDailyReportViewDTO extends BaseDataObject
{
    public function __construct(
        public readonly int $eventId,
        public readonly bool $enabled,
        public readonly string $sendTime,
        public readonly string $timezone,
        public readonly bool $copySupervisors,
        public readonly ?string $lastSentOn,
        public readonly int $teamContactsCount,
        public readonly string $preview,
    ) {}
}
