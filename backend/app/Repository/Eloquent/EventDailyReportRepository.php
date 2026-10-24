<?php

declare(strict_types=1);

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\Enums\ProductType;
use HiEvents\DomainObjects\EventDailyReportDomainObject;
use HiEvents\DomainObjects\Generated\EventDailyReportDomainObjectAbstract;
use HiEvents\DomainObjects\Status\AttendeeStatus;
use HiEvents\DomainObjects\Status\OrderRefundStatus;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Models\EventDailyReport;
use HiEvents\Repository\Interfaces\EventDailyReportRepositoryInterface;
use HiEvents\Services\Domain\Event\DailyReport\DTO\EventRegistrationStatsDTO;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * @extends BaseRepository<EventDailyReportDomainObject>
 */
class EventDailyReportRepository extends BaseRepository implements EventDailyReportRepositoryInterface
{
    protected function getModel(): string
    {
        return EventDailyReport::class;
    }

    public function getDomainObject(): string
    {
        return EventDailyReportDomainObject::class;
    }

    public function claimDay(int $reportId, string $localDate): bool
    {
        return EventDailyReport::query()
            ->whereKey($reportId)
            ->where(fn ($query) => $query
                ->whereNull(EventDailyReportDomainObjectAbstract::LAST_SENT_ON)
                ->orWhere(EventDailyReportDomainObjectAbstract::LAST_SENT_ON, '<', $localDate))
            ->update([
                EventDailyReportDomainObjectAbstract::LAST_SENT_ON => $localDate,
                EventDailyReportDomainObjectAbstract::UPDATED_AT => now()->toDateTimeString(),
            ]) > 0;
    }

    /**
     * Paid registrations are the active attendees of completed orders that are not fully refunded, for ticket products
     * only: options (GENERAL products) and refund protection products are left out. Their revenue is the unit price
     * of their order line, which holds neither the platform fee nor the other fees and taxes.
     */
    public function findRegistrationStats(int $eventId, string $dayStartUtc, string $dayEndUtc, string $nowUtc): EventRegistrationStatsDTO
    {
        $unitPrice = '(SELECT order_items.price FROM order_items'
            .' WHERE order_items.order_id = attendees.order_id'
            .' AND order_items.product_price_id = attendees.product_price_id'
            .' AND order_items.deleted_at IS NULL ORDER BY order_items.id LIMIT 1)';

        $row = $this->paidAttendees($eventId)
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw('COUNT(*) FILTER (WHERE attendees.created_at >= ? AND attendees.created_at < ?) AS today', [$dayStartUtc, $dayEndUtc])
            ->selectRaw('COALESCE(SUM('.$unitPrice.'), 0) AS revenue')
            ->first();

        $openCarts = DB::table('orders')
            ->where('orders.event_id', $eventId)
            ->whereNull('orders.deleted_at')
            ->where('orders.status', OrderStatus::RESERVED->name)
            ->where('orders.reserved_until', '>', $nowUtc)
            ->count();

        return new EventRegistrationStatsDTO(
            totalRegistrations: (int) ($row->total ?? 0),
            todayRegistrations: (int) ($row->today ?? 0),
            revenue: round((float) ($row->revenue ?? 0), 2),
            openCarts: $openCarts,
        );
    }

    private function paidAttendees(int $eventId): Builder
    {
        return DB::table('attendees')
            ->join('orders', 'orders.id', '=', 'attendees.order_id')
            ->join('products', 'products.id', '=', 'attendees.product_id')
            ->where('attendees.event_id', $eventId)
            ->whereNull('attendees.deleted_at')
            ->where('attendees.status', AttendeeStatus::ACTIVE->name)
            ->whereNull('orders.deleted_at')
            ->where('orders.status', OrderStatus::COMPLETED->name)
            ->where(fn (Builder $query) => $query
                ->whereNull('orders.refund_status')
                ->orWhere('orders.refund_status', '!=', OrderRefundStatus::REFUNDED->name))
            ->where('products.product_type', ProductType::TICKET->name)
            ->where('products.is_refund_protection', false);
    }
}
