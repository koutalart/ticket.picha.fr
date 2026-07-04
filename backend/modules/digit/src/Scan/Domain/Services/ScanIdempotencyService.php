<?php

declare(strict_types=1);

namespace Digit\Scan\Domain\Services;

use Digit\Scan\Domain\DTO\ScanOutcomeDTO;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class ScanIdempotencyService
{
    private const TTL_SECONDS = 86400;

    public function find(string $idempotencyKey): ?ScanOutcomeDTO
    {
        try {
            $cached = Cache::store('redis')->get($this->key($idempotencyKey));

            return $cached ? ScanOutcomeDTO::fromArray($cached) : null;
        } catch (Throwable $e) {
            Log::warning('digit.scan.idempotency_lookup_failed_failing_open', ['error' => $e->getMessage()]);

            return null;
        }
    }

    public function remember(string $idempotencyKey, ScanOutcomeDTO $outcome): void
    {
        try {
            Cache::store('redis')->put($this->key($idempotencyKey), $outcome->toArray(), self::TTL_SECONDS);
        } catch (Throwable $e) {
            Log::warning('digit.scan.idempotency_write_failed', ['error' => $e->getMessage()]);
        }
    }

    private function key(string $idempotencyKey): string
    {
        return 'digit:scan-idempotency:' . $idempotencyKey;
    }
}
