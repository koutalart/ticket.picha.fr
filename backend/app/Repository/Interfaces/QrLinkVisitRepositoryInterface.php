<?php

declare(strict_types=1);

namespace HiEvents\Repository\Interfaces;

use Carbon\CarbonInterface;
use HiEvents\DomainObjects\QrLinkVisitDomainObject;

/**
 * @extends RepositoryInterface<QrLinkVisitDomainObject>
 */
interface QrLinkVisitRepositoryInterface extends RepositoryInterface
{
    public function hasScanFromVisitor(int $qrLinkId, string $visitorHash): bool;

    /**
     * @return array<int, array{date: string, scans: int, unique_scans: int, clicks: int}>
     */
    public function getDailyCounts(int $qrLinkId, CarbonInterface $from, string $timezone): array;

    /**
     * @return array<int, array{key: string|null, count: int}>
     */
    public function getScanBreakdown(int $qrLinkId, CarbonInterface $from, string $column): array;

    /**
     * @return array<int, array{key: string|null, count: int}>
     */
    public function getClickBreakdown(int $qrLinkId, CarbonInterface $from): array;

    /**
     * @return array<int, array{hour: int, count: int}>
     */
    public function getScansByHour(int $qrLinkId, CarbonInterface $from, string $timezone): array;
}
