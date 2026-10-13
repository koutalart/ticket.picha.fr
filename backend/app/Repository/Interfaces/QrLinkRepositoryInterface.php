<?php

declare(strict_types=1);

namespace HiEvents\Repository\Interfaces;

use Carbon\CarbonInterface;
use HiEvents\DomainObjects\QrLinkDomainObject;

/**
 * @extends RepositoryInterface<QrLinkDomainObject>
 */
interface QrLinkRepositoryInterface extends RepositoryInterface
{
    /**
     * @return array<int, array{orders_count: int, revenue_gross: float}>
     */
    public function getOrderTotalsByQrLinkIds(array $qrLinkIds, ?CarbonInterface $from = null): array;

    public function incrementVisitCounters(int $qrLinkId, bool $isScan, bool $isUniqueScan): void;
}
