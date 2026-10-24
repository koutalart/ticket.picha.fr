<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Event\DailyReport;

use HiEvents\Exceptions\EventDailyReportException;
use HiEvents\Services\Application\Handlers\Event\DailyReport\DTO\EventDailyReportViewDTO;
use HiEvents\Services\Domain\Event\DailyReport\EventDailyReportService;
use HiEvents\Services\Domain\Sms\Team\OrganizerTeamContactService;

class GetEventDailyReportHandler
{
    public function __construct(
        private readonly EventDailyReportService $reportService,
        private readonly OrganizerTeamContactService $teamContactService,
    ) {}

    /**
     * @throws EventDailyReportException
     */
    public function handle(int $eventId): EventDailyReportViewDTO
    {
        $report = $this->reportService->find($eventId);
        $preview = $this->reportService->build($eventId);

        return new EventDailyReportViewDTO(
            eventId: $eventId,
            enabled: (bool) $report?->getEnabled(),
            sendTime: $report?->getSendTime() ?? EventDailyReportService::DEFAULT_SEND_TIME,
            timezone: $report?->getTimezone() ?? EventDailyReportService::DEFAULT_TIMEZONE,
            copySupervisors: (bool) $report?->getCopySupervisors(),
            lastSentOn: $report?->getLastSentOn(),
            teamContactsCount: $this->teamContactService->list($preview->organizerId)->count(),
            preview: $preview->text,
        );
    }
}
