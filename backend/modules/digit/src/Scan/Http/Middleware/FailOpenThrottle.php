<?php

declare(strict_types=1);

namespace Digit\Scan\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Rate limiting for the scan endpoint, without sacrificing the Module 3
 * fail-open guarantee. Laravel's built-in `throttle` middleware reads/writes
 * the cache store (Redis in this app) and does NOT fail open - if Redis is
 * unavailable, it throws, and every scan returns HTTP 500 for the duration
 * of the outage. That directly violates "the scan must NEVER be blocked"
 * from the Module 3 hardening requirements. This middleware wraps the same
 * rate-limiting logic in a try/catch: any failure to reach the rate limiter
 * store is logged and the request is allowed through, exactly like
 * ScanLockService's lock acquisition.
 */
class FailOpenThrottle
{
    private const MAX_ATTEMPTS = 120;
    private const DECAY_SECONDS = 60;

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $key = $this->resolveKey($request);

            if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
                return response()->json([
                    'error' => 'Too many scan requests, please slow down',
                ], 429);
            }

            RateLimiter::hit($key, self::DECAY_SECONDS);
        } catch (Throwable $e) {
            Log::warning('digit.scan.rate_limiter_unavailable_failing_open', [
                'error' => $e->getMessage(),
            ]);
        }

        return $next($request);
    }

    private function resolveKey(Request $request): string
    {
        $device = $request->attributes->get('digit_scan_device');

        if ($device !== null) {
            return 'digit-scan:device:' . $device->id;
        }

        return 'digit-scan:ip:' . $request->ip();
    }
}
