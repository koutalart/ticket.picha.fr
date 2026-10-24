<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Event\DailyReport;

use HiEvents\Exceptions\EventDailyReportException;
use HiEvents\Services\Application\Handlers\Event\DailyReport\DTO\EventDailyReportViewDTO;
use HiEvents\Services\Application\Handlers\Event\DailyReport\DTO\UpdateEventDailyReportDTO;
use HiEvents\Services\Domain\Event\DailyReport\EventDailyReportService;

class UpdateEventDailyReportHandler
{
    public function __construct(
        private readonly EventDailyReportService $reportService,
        private readonly GetEventDailyReportHandler $getHandler,
    ) {}

    /**
     * @throws EventDailyReportException
     */
    public function handle(UpdateEventDailyReportDTO $dto): EventDailyReportViewDTO
    {
        $this->reportService->configure(
            eventId: $dto->eventId,
            enabled: $dto->enabled,
            sendTime: $dto->sendTime,
            timezone: $dto->timezone,
            copySupervisors: $dto->copySupervisors,
        );

        return $this->getHandler->handle($dto->eventId);
    }
}
