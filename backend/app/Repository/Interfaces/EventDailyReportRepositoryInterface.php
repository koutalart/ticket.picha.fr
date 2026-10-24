<?php

declare(strict_types=1);

namespace HiEvents\Repository\Interfaces;

use HiEvents\DomainObjects\EventDailyReportDomainObject;
use HiEvents\Services\Domain\Event\DailyReport\DTO\EventRegistrationStatsDTO;

/**
 * @extends RepositoryInterface<EventDailyReportDomainObject>
 */
interface EventDailyReportRepositoryInterface extends RepositoryInterface
{
    /**
     * Marks the report as sent for the local day, unless it already was. True when this call claimed the day.
     */
    public function claimDay(int $reportId, string $localDate): bool;

    public function findRegistrationStats(int $eventId, string $dayStartUtc, string $dayEndUtc, string $nowUtc): EventRegistrationStatsDTO;
}
