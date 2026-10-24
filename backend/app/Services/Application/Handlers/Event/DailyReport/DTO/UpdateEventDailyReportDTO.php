<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Event\DailyReport\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class UpdateEventDailyReportDTO extends BaseDataObject
{
    public function __construct(
        public readonly int $eventId,
        public readonly ?bool $enabled = null,
        public readonly ?string $sendTime = null,
        public readonly ?string $timezone = null,
        public readonly ?bool $copySupervisors = null,
    ) {}
}
