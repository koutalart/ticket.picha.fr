<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Event\DailyReport\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;
use HiEvents\Services\Domain\Sms\Team\DTO\SmsBulkSendResultDTO;

class EventDailyReportSendResultDTO extends BaseDataObject
{
    public function __construct(
        public readonly int $eventId,
        public readonly EventDailyReportDTO $report,
        public readonly SmsBulkSendResultDTO $team,
        public readonly ?SmsBulkSendResultDTO $supervision = null,
        public readonly ?EventDailyActivityDTO $activity = null,
    ) {}
}
