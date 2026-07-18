<?php

declare(strict_types=1);

namespace Digit\Scan\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Authenticates scanning devices against backend/modules/digit's own
 * digit_scan_devices table. This is intentionally separate from Hi.Events'
 * core JWT-based user auth: scanning devices are not organizer users, they
 * are physical/mobile devices at a door, provisioned via
 * `php artisan digit:scan:device:create`.
 *
 * Token is opaque, stored only as a sha256 hash (never in plaintext), and
 * optionally scoped to a single check-in list so a device provisioned for
 * one door cannot be replayed against another.
 */
class AuthenticateScanDevice
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $this->extractToken($request);

        if ($token === null || $token === '') {
            return $this->unauthorized('Missing scan device token');
        }

        $tokenHash = hash('sha256', $token);

        $device = DB::table('digit_scan_devices')
            ->where('token_hash', $tokenHash)
            ->whereNull('revoked_at')
            ->first();

        if ($device === null) {
            return $this->unauthorized('Invalid or revoked scan device token');
        }

        $checkInListShortId = $request->route('check_in_list_short_id');

        if ($checkInListShortId !== null) {
            $checkInList = DB::table('check_in_lists')
                ->where('short_id', $checkInListShortId)
                ->first();

            if ($checkInList === null) {
                return $this->unauthorized('This device is not authorized for this check-in list');
            }

            if (!$this->isAuthorizedForCheckInList($device, $checkInList)) {
                return $this->unauthorized('This device is not authorized for this check-in list');
            }
        }

        $request->attributes->set('digit_scan_device', $device);

        // Bookkeeping only - never let a failure here block a real scan.
        try {
            DB::table('digit_scan_devices')
                ->where('id', $device->id)
                ->update(['last_used_at' => now()]);
        } catch (Throwable $e) {
            Log::warning('digit.scan.device_last_used_update_failed', ['error' => $e->getMessage()]);
        }

        return $next($request);
    }

    /**
     * Authorization order (revoked_at is already enforced above, at lookup
     * time - this method never re-checks it):
     *   1. all_check_in_lists=true -> authorized for any check-in list that
     *      belongs to the same event_id as the device.
     *   2. Otherwise, an explicit row in digit_scan_device_check_in_lists
     *      for this device + this check-in list -> authorized.
     *   3. Otherwise, if the device has ANY row at all in
     *      digit_scan_device_check_in_lists, it is explicitly pivot-scoped
     *      and a miss above means "not authorized" - it must NOT fall
     *      through to the legacy null fallback below.
     *   4. Otherwise, legacy fallback on digit_scan_devices.check_in_list_id:
     *      - null (device provisioned before multi-list support, never
     *        scoped, and never given any pivot row) -> authorized, exactly
     *        as before this change.
     *      - set -> authorized only if it matches the requested list.
     */
    private function isAuthorizedForCheckInList(object $device, object $checkInList): bool
    {
        if ($device->all_check_in_lists) {
            return (int) $checkInList->event_id === (int) $device->event_id;
        }

        $hasPivotAssignment = DB::table('digit_scan_device_check_in_lists')
            ->where('device_id', $device->id)
            ->where('check_in_list_id', $checkInList->id)
            ->exists();

        if ($hasPivotAssignment) {
            return true;
        }

        $hasAnyPivotRows = DB::table('digit_scan_device_check_in_lists')
            ->where('device_id', $device->id)
            ->exists();

        if ($hasAnyPivotRows) {
            return false;
        }

        if ($device->check_in_list_id === null) {
            return true;
        }

        return (int) $checkInList->id === (int) $device->check_in_list_id;
    }

    private function extractToken(Request $request): ?string
    {
        $header = $request->header('Authorization');

        if ($header !== null && str_starts_with($header, 'Bearer ')) {
            return substr($header, 7);
        }

        return $request->header('X-Scan-Token');
    }

    private function unauthorized(string $message): Response
    {
        return response()->json(['error' => $message], 401);
    }
}
