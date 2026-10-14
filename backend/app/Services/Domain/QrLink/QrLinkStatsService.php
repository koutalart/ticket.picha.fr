<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\QrLink;

use Carbon\Carbon;
use HiEvents\DomainObjects\QrLinkDomainObject;
use HiEvents\Repository\Interfaces\QrLinkRepositoryInterface;
use HiEvents\Repository\Interfaces\QrLinkVisitRepositoryInterface;
use HiEvents\Services\Domain\QrLink\DTO\QrLinkStatsDTO;

class QrLinkStatsService
{
    public function __construct(
        private readonly QrLinkVisitRepositoryInterface $qrLinkVisitRepository,
        private readonly QrLinkRepositoryInterface $qrLinkRepository,
    ) {}

    public function getStats(QrLinkDomainObject $qrLink, ?int $days, string $timezone): QrLinkStatsDTO
    {
        $from = $days
            ? Carbon::now($timezone)->startOfDay()->subDays($days - 1)
            : Carbon::parse($qrLink->getCreatedAt(), 'UTC');

        $id = $qrLink->getId();
        $daily = $this->qrLinkVisitRepository->getDailyCounts($id, $from, $timezone);
        $orderTotals = $this->qrLinkRepository->getOrderTotalsByQrLinkIds([$id], $from)[$id] ?? ['orders_count' => 0, 'revenue_gross' => 0.0];

        $scans = array_sum(array_column($daily, 'scans'));
        $uniqueScans = array_sum(array_column($daily, 'unique_scans'));

        return new QrLinkStatsDTO(
            qr_link_id: $id,
            days: $days,
            timezone: $timezone,
            scans: $scans,
            unique_scans: $uniqueScans,
            clicks: array_sum(array_column($daily, 'clicks')),
            orders: $orderTotals['orders_count'],
            revenue_gross: $orderTotals['revenue_gross'],
            conversion_rate: $uniqueScans > 0 ? round($orderTotals['orders_count'] / $uniqueScans * 100, 1) : null,
            daily: $daily,
            hours: $this->qrLinkVisitRepository->getScansByHour($id, $from, $timezone),
            devices: $this->qrLinkVisitRepository->getScanBreakdown($id, $from, 'device_type'),
            operating_systems: $this->qrLinkVisitRepository->getScanBreakdown($id, $from, 'os'),
            browsers: $this->qrLinkVisitRepository->getScanBreakdown($id, $from, 'browser'),
            countries: $this->qrLinkVisitRepository->getScanBreakdown($id, $from, 'country_code'),
            referrers: $this->qrLinkVisitRepository->getScanBreakdown($id, $from, 'referrer_host'),
            clicks_by_target: $this->qrLinkVisitRepository->getClickBreakdown($id, $from),
        );
    }
}
