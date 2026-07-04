<?php

declare(strict_types=1);

namespace Digit\Scan\Domain\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class TicketValidityCacheService
{
    private const TTL_SECONDS = 120;

    public function isKnownInvalid(int $checkInListId, int $attendeeId): bool
    {
        try {
            return (bool) Cache::store('redis')->get($this->key($checkInListId, $attendeeId), false);
        } catch (Throwable $e) {
            Log::warning('digit.scan.validity_cache_read_failed_failing_open', ['error' => $e->getMessage()]);

            return false;
        }
    }

    public function markInvalid(int $checkInListId, int $attendeeId): void
    {
        try {
            Cache::store('redis')->put($this->key($checkInListId, $attendeeId), true, self::TTL_SECONDS);
        } catch (Throwable $e) {
            Log::warning('digit.scan.validity_cache_write_failed', ['error' => $e->getMessage()]);
        }
    }

    public function invalidate(int $checkInListId, int $attendeeId): void
    {
        try {
            Cache::store('redis')->forget($this->key($checkInListId, $attendeeId));
        } catch (Throwable) {
            // best effort only
        }
    }

    private function key(int $checkInListId, int $attendeeId): string
    {
        return sprintf('digit:scan-invalid:%d:%d', $checkInListId, $attendeeId);
    }
}
