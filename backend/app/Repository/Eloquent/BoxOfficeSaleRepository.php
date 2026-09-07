<?php

declare(strict_types=1);

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\BoxOfficeSaleDomainObject;
use HiEvents\Models\Attendee;
use HiEvents\Models\AttendeeCheckIn;
use HiEvents\Models\BoxOfficeSale;
use HiEvents\Models\BoxOfficeSaleItem;
use HiEvents\Repository\Interfaces\BoxOfficeSaleRepositoryInterface;
use Illuminate\Support\Collection;

/**
 * @extends BaseRepository<BoxOfficeSaleDomainObject>
 */
class BoxOfficeSaleRepository extends BaseRepository implements BoxOfficeSaleRepositoryInterface
{
    protected function getModel(): string
    {
        return BoxOfficeSale::class;
    }

    public function getDomainObject(): string
    {
        return BoxOfficeSaleDomainObject::class;
    }

    public function createItems(int $saleId, array $rows): void
    {
        foreach ($rows as $row) {
            BoxOfficeSaleItem::query()->create([
                'box_office_sale_id' => $saleId,
                'product_id' => $row['product_id'],
                'product_price_id' => $row['product_price_id'],
                'unit_amount' => $row['unit_amount'],
                'attendee_id' => $row['attendee_id'],
                'order_id' => $row['order_id'],
            ]);
        }
    }

    public function findItemsBySaleId(int $saleId): Collection
    {
        return BoxOfficeSaleItem::query()
            ->where('box_office_sale_id', $saleId)
            ->orderBy('id')
            ->get();
    }

    public function paginateCompletedForKiosk(int $eventId, ?int $agentUserId, int $page, int $perPage = 20, ?string $search = null): array
    {
        $query = BoxOfficeSale::query()
            ->where('box_office_sales.event_id', $eventId)
            ->where('box_office_sales.status', 'COMPLETED');

        if ($agentUserId !== null) {
            $query->where('box_office_sales.agent_user_id', $agentUserId);
        }

        $query
            ->leftJoin('users', 'users.id', '=', 'box_office_sales.agent_user_id')
            ->leftJoin('orders', 'orders.id', '=', 'box_office_sales.order_id')
            ->leftJoin('attendees', 'attendees.id', '=', 'box_office_sales.attendee_id');

        $search = is_string($search) ? trim($search) : '';
        if ($search !== '') {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->where(function ($nested) use ($like) {
                $nested
                    ->where('attendees.first_name', 'ilike', $like)
                    ->orWhere('attendees.last_name', 'ilike', $like)
                    ->orWhereRaw(
                        "(COALESCE(attendees.first_name, '') || ' ' || COALESCE(attendees.last_name, '')) ilike ?",
                        [$like],
                    )
                    ->orWhere('attendees.email', 'ilike', $like)
                    ->orWhere('attendees.public_id', 'ilike', $like)
                    ->orWhere('orders.public_id', 'ilike', $like)
                    ->orWhere('box_office_sales.phone', 'ilike', $like)
                    ->orWhereExists(function ($sub) use ($like) {
                        $sub->selectRaw('1')
                            ->from('box_office_sale_items as bos_items')
                            ->join('attendees as item_attendees', 'item_attendees.id', '=', 'bos_items.attendee_id')
                            ->whereColumn('bos_items.box_office_sale_id', 'box_office_sales.id')
                            ->where(function ($names) use ($like) {
                                $names
                                    ->where('item_attendees.first_name', 'ilike', $like)
                                    ->orWhere('item_attendees.last_name', 'ilike', $like)
                                    ->orWhereRaw(
                                        "(COALESCE(item_attendees.first_name, '') || ' ' || COALESCE(item_attendees.last_name, '')) ilike ?",
                                        [$like],
                                    )
                                    ->orWhere('item_attendees.email', 'ilike', $like)
                                    ->orWhere('item_attendees.public_id', 'ilike', $like);
                            });
                    });
            });
        }

        $paginator = $query
            ->select([
                'box_office_sales.id',
                'box_office_sales.created_at',
                'box_office_sales.payment_method',
                'box_office_sales.amount',
                'box_office_sales.amount_collected',
                'box_office_sales.phone',
                'box_office_sales.agent_user_id',
                'box_office_sales.attendee_id',
                'users.first_name as agent_first_name',
                'users.last_name as agent_last_name',
                'users.email as agent_email',
                'attendees.first_name as attendee_first_name',
                'attendees.last_name as attendee_last_name',
                'attendees.public_id as attendee_public_id',
                'orders.public_id as order_public_id',
            ])
            ->selectRaw(
                '(select count(*) from box_office_sale_items where box_office_sale_items.box_office_sale_id = box_office_sales.id) as items_count',
            )
            ->orderByDesc('box_office_sales.id')
            ->toBase()
            ->paginate(
                perPage: $perPage,
                columns: ['*'],
                pageName: 'page',
                page: $page,
            );

        $items = [];
        $saleAttendeeIds = [];
        foreach ($paginator->items() as $row) {
            $ticketCount = (int) ($row->items_count ?? 0);
            $saleId = (int) $row->id;
            $createdAt = $row->created_at;
            $saleAttendeeIds[$saleId] = (int) ($row->attendee_id ?? 0);
            $items[] = [
                'id' => $saleId,
                'created_at' => is_object($createdAt) && method_exists($createdAt, 'toIso8601String')
                    ? $createdAt->toIso8601String()
                    : (string) $createdAt,
                'payment_method' => $row->payment_method,
                'amount' => (float) $row->amount,
                'amount_collected' => (float) $row->amount_collected,
                'phone' => $row->phone,
                'ticket_count' => $ticketCount > 0 ? $ticketCount : 1,
                'agent_user_id' => (int) $row->agent_user_id,
                'agent_name' => trim(($row->agent_first_name ?? '').' '.($row->agent_last_name ?? ''))
                    ?: (string) ($row->agent_email ?? ''),
                'attendee_name' => trim(($row->attendee_first_name ?? '').' '.($row->attendee_last_name ?? '')),
                'attendee_public_id' => $row->attendee_public_id,
                'order_public_id' => $row->order_public_id,
            ];
        }

        $items = $this->hydrateKioskScanAndPrintMeta($items, $saleAttendeeIds);

        return [
            'items' => $items,
            'total' => $paginator->total(),
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
        ];
    }

    public function aggregateCompletedForKiosk(int $eventId, ?int $agentUserId): array
    {
        $base = BoxOfficeSale::query()
            ->where('event_id', $eventId)
            ->where('status', 'COMPLETED');

        if ($agentUserId !== null) {
            $base->where('agent_user_id', $agentUserId);
        }

        $totals = (clone $base)
            ->selectRaw('COUNT(*) as sales_count')
            ->selectRaw('COALESCE(SUM(amount), 0) as total_amount')
            ->selectRaw('COALESCE(SUM(amount_collected), 0) as total_collected')
            ->first();

        $itemTickets = BoxOfficeSaleItem::query()
            ->whereIn('box_office_sale_id', (clone $base)->select('id'))
            ->count();

        $legacyTickets = (clone $base)->doesntHave('items')->count();

        $byPaymentMethod = (clone $base)
            ->selectRaw('payment_method')
            ->selectRaw('COUNT(*) as sales_count')
            ->selectRaw('COALESCE(SUM(amount), 0) as total_amount')
            ->selectRaw('COALESCE(SUM(amount_collected), 0) as total_collected')
            ->groupBy('payment_method')
            ->orderBy('payment_method')
            ->get()
            ->map(static fn ($row) => [
                'payment_method' => $row->payment_method,
                'sales_count' => (int) $row->sales_count,
                'total_amount' => (float) $row->total_amount,
                'total_collected' => (float) $row->total_collected,
            ])
            ->all();

        $byAgent = (clone $base)
            ->leftJoin('users', 'users.id', '=', 'box_office_sales.agent_user_id')
            ->selectRaw('box_office_sales.agent_user_id')
            ->selectRaw("TRIM(CONCAT(COALESCE(users.first_name, ''), ' ', COALESCE(users.last_name, ''))) as agent_name")
            ->selectRaw('COUNT(*) as sales_count')
            ->selectRaw('COALESCE(SUM(box_office_sales.amount), 0) as total_amount')
            ->selectRaw('COALESCE(SUM(box_office_sales.amount_collected), 0) as total_collected')
            ->groupBy('box_office_sales.agent_user_id', 'users.first_name', 'users.last_name')
            ->orderByDesc('total_collected')
            ->get()
            ->map(static fn ($row) => [
                'agent_user_id' => (int) $row->agent_user_id,
                'agent_name' => trim((string) $row->agent_name),
                'sales_count' => (int) $row->sales_count,
                'total_amount' => (float) $row->total_amount,
                'total_collected' => (float) $row->total_collected,
            ])
            ->all();

        return [
            'sales_count' => (int) ($totals?->sales_count ?? 0),
            'ticket_count' => $itemTickets + $legacyTickets,
            'total_amount' => (float) ($totals?->total_amount ?? 0),
            'total_collected' => (float) ($totals?->total_collected ?? 0),
            'by_payment_method' => $byPaymentMethod,
            'by_agent' => $byAgent,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array<int, int>  $saleAttendeeIds
     * @return list<array<string, mixed>>
     */
    private function hydrateKioskScanAndPrintMeta(array $items, array $saleAttendeeIds): array
    {
        if ($items === []) {
            return $items;
        }

        $saleIds = array_map(static fn (array $item) => (int) $item['id'], $items);
        $attendeeIdsBySale = [];

        foreach (BoxOfficeSaleItem::query()
            ->whereIn('box_office_sale_id', $saleIds)
            ->get(['box_office_sale_id', 'attendee_id']) as $itemRow
        ) {
            if ($itemRow->attendee_id === null) {
                continue;
            }
            $attendeeIdsBySale[(int) $itemRow->box_office_sale_id][] = (int) $itemRow->attendee_id;
        }

        foreach ($saleIds as $saleId) {
            if (! empty($attendeeIdsBySale[$saleId])) {
                continue;
            }
            $fallbackId = $saleAttendeeIds[$saleId] ?? 0;
            if ($fallbackId > 0) {
                $attendeeIdsBySale[$saleId] = [$fallbackId];
            }
        }

        $allAttendeeIds = [];
        foreach ($attendeeIdsBySale as $ids) {
            foreach ($ids as $attendeeId) {
                $allAttendeeIds[$attendeeId] = $attendeeId;
            }
        }
        $allAttendeeIds = array_values($allAttendeeIds);
        $publicIdsByAttendee = $allAttendeeIds === []
            ? collect()
            : Attendee::query()->whereIn('id', $allAttendeeIds)->pluck('public_id', 'id');
        $checkedInIds = $allAttendeeIds === []
            ? []
            : AttendeeCheckIn::query()
                ->whereIn('attendee_id', $allAttendeeIds)
                ->distinct()
                ->pluck('attendee_id')
                ->map(static fn ($id) => (int) $id)
                ->all();
        $checkedInSet = array_fill_keys($checkedInIds, true);

        foreach ($items as $index => $item) {
            $ids = array_values(array_unique($attendeeIdsBySale[(int) $item['id']] ?? []));
            $publicIds = [];
            $checkedInCount = 0;
            foreach ($ids as $attendeeId) {
                $publicId = $publicIdsByAttendee[$attendeeId] ?? null;
                if (is_string($publicId) && $publicId !== '') {
                    $publicIds[] = $publicId;
                }
                if (isset($checkedInSet[$attendeeId])) {
                    $checkedInCount++;
                }
            }

            $items[$index]['attendee_public_ids'] = $publicIds;
            $items[$index]['checked_in_count'] = $checkedInCount;
        }

        return $items;
    }
}
