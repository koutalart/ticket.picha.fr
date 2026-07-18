<?php

declare(strict_types=1);

namespace Digit\Devices\Domain\Services;

use Illuminate\Support\Facades\DB;

/**
 * Single write path for digit_scan_device_check_in_lists. No other file
 * writes to this table. Read-side consumption belongs to
 * AuthenticateScanDevice (Etape B3/4, not touched by this service).
 */
class DeviceCheckInListAssignmentService
{
    public function assign(int $deviceId, array $checkInListIds, ?string $performedBy = null): void
    {
        DB::transaction(function () use ($deviceId, $checkInListIds, $performedBy) {
            $before = $this->list($deviceId);

            foreach ($checkInListIds as $checkInListId) {
                DB::table('digit_scan_device_check_in_lists')->updateOrInsert(
                    ['device_id' => $deviceId, 'check_in_list_id' => (int) $checkInListId],
                    ['updated_at' => now(), 'created_at' => now()]
                );
            }

            $this->logChange($deviceId, 'check_in_lists_assigned', $before, $this->list($deviceId), $performedBy);
        });
    }

    public function remove(int $deviceId, int $checkInListId, ?string $performedBy = null): void
    {
        DB::transaction(function () use ($deviceId, $checkInListId, $performedBy) {
            $before = $this->list($deviceId);

            DB::table('digit_scan_device_check_in_lists')
                ->where('device_id', $deviceId)
                ->where('check_in_list_id', $checkInListId)
                ->delete();

            $this->logChange($deviceId, 'check_in_list_removed', $before, $this->list($deviceId), $performedBy);
        });
    }

    public function replace(int $deviceId, array $checkInListIds, ?string $performedBy = null): void
    {
        DB::transaction(function () use ($deviceId, $checkInListIds, $performedBy) {
            $before = $this->list($deviceId);

            DB::table('digit_scan_device_check_in_lists')->where('device_id', $deviceId)->delete();

            foreach ($checkInListIds as $checkInListId) {
                DB::table('digit_scan_device_check_in_lists')->insert([
                    'device_id' => $deviceId,
                    'check_in_list_id' => (int) $checkInListId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $this->logChange($deviceId, 'check_in_lists_replaced', $before, $this->list($deviceId), $performedBy);
        });
    }

    public function list(int $deviceId): array
    {
        return DB::table('digit_scan_device_check_in_lists')
            ->where('device_id', $deviceId)
            ->pluck('check_in_list_id')
            ->map(fn ($id) => (int) $id)
            ->sort()
            ->values()
            ->toArray();
    }

    private function logChange(int $deviceId, string $action, array $before, array $after, ?string $performedBy): void
    {
        DB::table('digit_scan_device_audit_log')->insert([
            'device_id' => $deviceId,
            'action' => $action,
            'performed_by' => $performedBy,
            'old_value' => json_encode(['check_in_list_ids' => $before]),
            'new_value' => json_encode(['check_in_list_ids' => $after]),
            'created_at' => now(),
        ]);
    }
}
