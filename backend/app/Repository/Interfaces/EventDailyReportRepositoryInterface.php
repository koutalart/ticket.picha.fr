<?php

declare(strict_types=1);

namespace HiEvents\Repository\Interfaces;

use HiEvents\DomainObjects\EventDailyReportDomainObject;
use HiEvents\Services\Domain\Event\DailyReport\DTO\EventActivityStatsDTO;
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

    /**
     * The exchanges of the day for an event: SMS registration confirmations sent, people who wrote by SMS to the
     * organizer (team, supervision and the given numbers left aside) or through the live chat of the event, and the
     * refund requests of the event still in progress.
     *
     * @param  array<int, string>  $excludedPhones
     */
    public function findActivity(int $eventId, int $organizerId, string $dayStartUtc, string $dayEndUtc, array $excludedPhones): EventActivityStatsDTO;
}
