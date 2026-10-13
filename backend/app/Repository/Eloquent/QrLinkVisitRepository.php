<?php

declare(strict_types=1);

namespace HiEvents\Repository\Eloquent;

use Carbon\CarbonInterface;
use HiEvents\DomainObjects\Enums\QrLinkVisitType;
use HiEvents\DomainObjects\QrLinkVisitDomainObject;
use HiEvents\Models\QrLinkVisit;
use HiEvents\Repository\Interfaces\QrLinkVisitRepositoryInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * @extends BaseRepository<QrLinkVisitDomainObject>
 */
class QrLinkVisitRepository extends BaseRepository implements QrLinkVisitRepositoryInterface
{
    private const BREAKDOWN_COLUMNS = ['device_type', 'os', 'browser', 'country_code', 'referrer_host'];

    protected function getModel(): string
    {
        return QrLinkVisit::class;
    }

    public function getDomainObject(): string
    {
        return QrLinkVisitDomainObject::class;
    }

    public function hasScanFromVisitor(int $qrLinkId, string $visitorHash): bool
    {
        return QrLinkVisit::query()
            ->where('qr_link_id', $qrLinkId)
            ->where('visitor_hash', $visitorHash)
            ->where('type', QrLinkVisitType::SCAN->value)
            ->exists();
    }

    public function getDailyCounts(int $qrLinkId, CarbonInterface $from, string $timezone): array
    {
        $day = DB::raw("DATE(created_at AT TIME ZONE 'UTC' AT TIME ZONE ".DB::getPdo()->quote($timezone).') as day');

        return $this->visitsSince($qrLinkId, $from)
            ->select(
                $day,
                DB::raw("SUM(CASE WHEN type = 'SCAN' THEN 1 ELSE 0 END) as scans"),
                DB::raw("SUM(CASE WHEN type = 'SCAN' AND is_unique THEN 1 ELSE 0 END) as unique_scans"),
                DB::raw("SUM(CASE WHEN type = 'CLICK' THEN 1 ELSE 0 END) as clicks"),
            )
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->map(fn ($row) => [
                'date' => (string) $row->day,
                'scans' => (int) $row->scans,
                'unique_scans' => (int) $row->unique_scans,
                'clicks' => (int) $row->clicks,
            ])
            ->all();
    }

    public function getScanBreakdown(int $qrLinkId, CarbonInterface $from, string $column): array
    {
        if (! in_array($column, self::BREAKDOWN_COLUMNS, true)) {
            return [];
        }

        return $this->visitsSince($qrLinkId, $from)
            ->where('type', QrLinkVisitType::SCAN->value)
            ->select($column.' as key', DB::raw('COUNT(*) as count'))
            ->groupBy($column)
            ->orderByDesc('count')
            ->limit(10)
            ->get()
            ->map(fn ($row) => ['key' => $row->key, 'count' => (int) $row->count])
            ->all();
    }

    public function getClickBreakdown(int $qrLinkId, CarbonInterface $from): array
    {
        return $this->visitsSince($qrLinkId, $from)
            ->where('type', QrLinkVisitType::CLICK->value)
            ->select('target as key', DB::raw('COUNT(*) as count'))
            ->groupBy('target')
            ->orderByDesc('count')
            ->get()
            ->map(fn ($row) => ['key' => $row->key, 'count' => (int) $row->count])
            ->all();
    }

    public function getScansByHour(int $qrLinkId, CarbonInterface $from, string $timezone): array
    {
        $hour = DB::raw("EXTRACT(HOUR FROM created_at AT TIME ZONE 'UTC' AT TIME ZONE ".DB::getPdo()->quote($timezone).')::int as hour');

        return $this->visitsSince($qrLinkId, $from)
            ->where('type', QrLinkVisitType::SCAN->value)
            ->select($hour, DB::raw('COUNT(*) as count'))
            ->groupBy('hour')
            ->orderBy('hour')
            ->get()
            ->map(fn ($row) => ['hour' => (int) $row->hour, 'count' => (int) $row->count])
            ->all();
    }

    private function visitsSince(int $qrLinkId, CarbonInterface $from): Builder
    {
        return QrLinkVisit::query()
            ->where('qr_link_id', $qrLinkId)
            ->where('created_at', '>=', $from->copy()->utc());
    }
}
