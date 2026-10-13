<?php

declare(strict_types=1);

namespace HiEvents\Repository\Eloquent;

use Carbon\CarbonInterface;
use HiEvents\DomainObjects\QrLinkDomainObject;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Models\Order;
use HiEvents\Models\QrLink;
use HiEvents\Repository\Interfaces\QrLinkRepositoryInterface;
use Illuminate\Support\Facades\DB;

/**
 * @extends BaseRepository<QrLinkDomainObject>
 */
class QrLinkRepository extends BaseRepository implements QrLinkRepositoryInterface
{
    protected function getModel(): string
    {
        return QrLink::class;
    }

    public function getDomainObject(): string
    {
        return QrLinkDomainObject::class;
    }

    public function getOrderTotalsByQrLinkIds(array $qrLinkIds, ?CarbonInterface $from = null): array
    {
        if ($qrLinkIds === []) {
            return [];
        }

        return Order::query()
            ->whereIn('qr_link_id', $qrLinkIds)
            ->where('status', OrderStatus::COMPLETED->name)
            ->when($from, fn ($query) => $query->where('created_at', '>=', $from->copy()->utc()))
            ->groupBy('qr_link_id')
            ->select('qr_link_id', DB::raw('COUNT(*) as orders_count'), DB::raw('COALESCE(SUM(total_gross), 0) as revenue_gross'))
            ->get()
            ->mapWithKeys(fn ($row) => [
                (int) $row->qr_link_id => [
                    'orders_count' => (int) $row->orders_count,
                    'revenue_gross' => round((float) $row->revenue_gross, 2),
                ],
            ])
            ->all();
    }

    public function incrementVisitCounters(int $qrLinkId, bool $isScan, bool $isUniqueScan): void
    {
        $columns = $isScan
            ? ['scans_count' => 1] + ($isUniqueScan ? ['unique_scans_count' => 1] : [])
            : ['clicks_count' => 1];

        QrLink::query()->whereKey($qrLinkId)->incrementEach($columns);
    }
}
