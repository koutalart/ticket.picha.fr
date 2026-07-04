<?php

declare(strict_types=1);

namespace Digit\Scan\Domain\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class ScanLockService
{
    private const LOCK_TTL_SECONDS = 10;

    public function withBestEffortLock(int $checkInListId, int $attendeeId, callable $callback): mixed
    {
        $key = sprintf('digit:scan-lock:%d:%d', $checkInListId, $attendeeId);
        $lock = null;

        try {
            $lock = Cache::store('redis')->lock($key, self::LOCK_TTL_SECONDS);
            $acquired = $lock->get();

            if (!$acquired) {
                Log::info('digit.scan.lock_contended', [
                    'check_in_list_id' => $checkInListId,
                    'attendee_id' => $attendeeId,
                ]);
            }

            return $callback();
        } catch (Throwable $e) {
            Log::warning('digit.scan.lock_unavailable_failing_open', [
                'check_in_list_id' => $checkInListId,
                'attendee_id' => $attendeeId,
                'error' => $e->getMessage(),
            ]);

            return $callback();
        } finally {
            try {
                $lock?->release();
            } catch (Throwable) {
                // never let cleanup throw
            }
        }
    }
}
