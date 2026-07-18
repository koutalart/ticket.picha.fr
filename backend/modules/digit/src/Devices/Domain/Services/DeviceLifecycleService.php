<?php

declare(strict_types=1);

namespace Digit\Devices\Domain\Services;

use Digit\Devices\Domain\Exceptions\DeviceTransitionNotAllowedException;
use Illuminate\Support\Facades\DB;

/**
 * Single source of truth for a DIGIT scan device's lifecycle status.
 * Supersedes DeviceRevocationService (Etape B2) - revoke() is preserved
 * so DigitScanDeviceRevokeCommand's call site does not change.
 *
 * `revoked_at` remains the ONLY field AuthenticateScanDevice reads to
 * decide whether a token is usable - untouched by this service. `status`
 * is administrative/reporting only. Every transition writes both fields
 * together, atomically, through transitionDeviceStatus() below - there
 * is no other write path to status/revoked_at anywhere in the codebase.
 */
class DeviceLifecycleService
{
    private const ALLOWED_TRANSITIONS = [
        'ACTIVE' => ['DISABLED', 'LOST', 'REVOKED'],
        'DISABLED' => ['ACTIVE', 'LOST', 'REVOKED'],
        'LOST' => ['ACTIVE', 'DISABLED', 'REVOKED'],
        'REVOKED' => [],
    ];

    public function revoke(int $deviceId, ?string $performedBy = null): bool
    {
        return $this->transitionDeviceStatus($deviceId, 'REVOKED', $performedBy);
    }

    public function disable(int $deviceId, ?string $performedBy = null): bool
    {
        return $this->transitionDeviceStatus($deviceId, 'DISABLED', $performedBy);
    }

    public function markLost(int $deviceId, ?string $performedBy = null): bool
    {
        return $this->transitionDeviceStatus($deviceId, 'LOST', $performedBy);
    }

    public function reactivate(int $deviceId, ?string $performedBy = null): bool
    {
        return $this->transitionDeviceStatus($deviceId, 'ACTIVE', $performedBy);
    }

    private function transitionDeviceStatus(int $deviceId, string $targetStatus, ?string $performedBy): bool
    {
        return DB::transaction(function () use ($deviceId, $targetStatus, $performedBy) {
            $device = DB::table('digit_scan_devices')->where('id', $deviceId)->lockForUpdate()->first();

            if ($device === null) {
                return false;
            }

            $currentStatus = $device->status ?? 'ACTIVE';

            if ($currentStatus === $targetStatus) {
                return false;
            }

            $allowed = self::ALLOWED_TRANSITIONS[$currentStatus] ?? [];

            if (!in_array($targetStatus, $allowed, true)) {
                throw new DeviceTransitionNotAllowedException(
                    "Transition {$currentStatus} -> {$targetStatus} is not allowed for device {$deviceId}"
                );
            }

            $revokedAtValue = $targetStatus === 'ACTIVE' ? null : now()->toDateTimeString();

            DB::table('digit_scan_devices')->where('id', $deviceId)->update([
                'status' => $targetStatus,
                'revoked_at' => $revokedAtValue,
                'updated_at' => now(),
            ]);

            DB::table('digit_scan_device_audit_log')->insert([
                'device_id' => $deviceId,
                'action' => "status_changed:{$currentStatus}->{$targetStatus}",
                'performed_by' => $performedBy,
                'old_value' => json_encode(['status' => $currentStatus, 'revoked_at' => $device->revoked_at]),
                'new_value' => json_encode(['status' => $targetStatus, 'revoked_at' => $revokedAtValue]),
                'created_at' => now(),
            ]);

            return true;
        });
    }
}
