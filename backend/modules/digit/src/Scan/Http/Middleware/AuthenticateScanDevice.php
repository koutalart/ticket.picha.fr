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

        if ($device->check_in_list_id !== null && $checkInListShortId !== null) {
            $checkInList = DB::table('check_in_lists')
                ->where('short_id', $checkInListShortId)
                ->first();

            if ($checkInList === null || (int) $checkInList->id !== (int) $device->check_in_list_id) {
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
