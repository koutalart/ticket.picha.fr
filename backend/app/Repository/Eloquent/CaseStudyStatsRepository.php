<?php

namespace HiEvents\Repository\Eloquent;

use HiEvents\Repository\Interfaces\CaseStudyStatsRepositoryInterface;
use Illuminate\Support\Facades\DB;

class CaseStudyStatsRepository implements CaseStudyStatsRepositoryInterface
{
    private const CHANNEL_SQL = "CASE WHEN o.payment_provider IS NOT NULL THEN o.payment_provider WHEN o.total_gross = 0 THEN 'GRATUIT' ELSE 'HORS_LIGNE' END";

    public function getEvents(array $eventIds): array
    {
        return DB::table('events as e')
            ->join('organizers as org', 'org.id', '=', 'e.organizer_id')
            ->leftJoin('event_settings as es', 'es.event_id', '=', 'e.id')
            ->whereIn('e.id', $eventIds)
            ->whereNull('e.deleted_at')
            ->orderBy('e.id')
            ->select([
                'e.id', 'e.title', 'e.status', 'e.category', 'e.timezone',
                DB::raw("to_char((e.start_date AT TIME ZONE 'UTC') AT TIME ZONE e.timezone, 'YYYY-MM-DD\"T\"HH24:MI') as start_local"),
                DB::raw("to_char((e.end_date AT TIME ZONE 'UTC') AT TIME ZONE e.timezone, 'YYYY-MM-DD\"T\"HH24:MI') as end_local"),
                'org.name as organizer',
                'es.location_details',
                DB::raw("(SELECT MIN(created_at) FROM orders WHERE event_id = e.id AND status = 'COMPLETED') as first_sale_utc"),
                DB::raw("(SELECT COUNT(*) FROM attendees WHERE event_id = e.id AND status = 'ACTIVE' AND deleted_at IS NULL) as tickets_active"),
                DB::raw("(SELECT COUNT(*) FROM orders WHERE event_id = e.id AND status = 'COMPLETED' AND deleted_at IS NULL) as orders_completed"),
            ])
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    public function getTicketsByChannelAndProduct(int $eventId): array
    {
        return DB::table('attendees as a')
            ->join('orders as o', 'o.id', '=', 'a.order_id')
            ->join('products as p', 'p.id', '=', 'a.product_id')
            ->where('a.event_id', $eventId)
            ->where('a.status', 'ACTIVE')
            ->whereNull('a.deleted_at')
            ->groupBy(DB::raw(self::CHANNEL_SQL), 'p.title')
            ->orderByRaw('1, 2')
            ->select([DB::raw(self::CHANNEL_SQL.' as channel'), 'p.title as product', DB::raw('COUNT(*) as tickets')])
            ->get()
            ->map(fn ($row) => ['channel' => $row->channel, 'product' => $row->product, 'tickets' => (int) $row->tickets])
            ->all();
    }

    public function getTicketsByDay(int $eventId, string $timezone): array
    {
        $day = "((a.created_at AT TIME ZONE 'UTC') AT TIME ZONE ?)::date";

        return DB::table('attendees as a')
            ->join('orders as o', 'o.id', '=', 'a.order_id')
            ->where('a.event_id', $eventId)
            ->where('a.status', 'ACTIVE')
            ->whereNull('a.deleted_at')
            ->selectRaw("$day as day, ".self::CHANNEL_SQL.' as channel, COUNT(*) as tickets', [$timezone])
            ->groupByRaw('1, 2')
            ->orderByRaw('1, 2')
            ->get()
            ->map(fn ($row) => ['day' => (string) $row->day, 'channel' => $row->channel, 'tickets' => (int) $row->tickets])
            ->all();
    }

    public function getPrices(int $eventId): array
    {
        return DB::table('products as p')
            ->join('product_prices as pp', 'pp.product_id', '=', 'p.id')
            ->where('p.event_id', $eventId)
            ->whereNull('p.deleted_at')
            ->whereNull('pp.deleted_at')
            ->orderBy('p.id')
            ->orderBy('pp.id')
            ->select(['p.title as product', 'pp.label', 'pp.price', 'pp.initial_quantity_available as capacity', 'pp.quantity_sold'])
            ->get()
            ->map(fn ($row) => [
                'product' => $row->product,
                'label' => $row->label,
                'price' => (float) $row->price,
                'capacity' => $row->capacity === null ? null : (int) $row->capacity,
                'quantity_sold' => (int) $row->quantity_sold,
            ])
            ->all();
    }

    public function countCheckedInAttendees(int $eventId): int
    {
        return (int) DB::table('attendee_check_ins')
            ->where('event_id', $eventId)
            ->whereNull('deleted_at')
            ->distinct()
            ->count('attendee_id');
    }

    public function getCheckInsByHour(int $eventId, string $timezone): array
    {
        return DB::table('attendee_check_ins as ci')
            ->where('ci.event_id', $eventId)
            ->whereNull('ci.deleted_at')
            ->selectRaw("to_char((ci.created_at AT TIME ZONE 'UTC') AT TIME ZONE ?, 'YYYY-MM-DD HH24:00') as hour, COUNT(*) as scans", [$timezone])
            ->groupByRaw('1')
            ->orderByRaw('1')
            ->get()
            ->map(fn ($row) => ['hour' => $row->hour, 'scans' => (int) $row->scans])
            ->all();
    }
}
