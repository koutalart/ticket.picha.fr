<?php

declare(strict_types=1);

namespace Digit\Bracelets\Security;

use Digit\Bracelets\Security\Models\DigitEventSecurityKey;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Manages the one HMAC signing secret per event. The secret is generated
 * once (256 bits of entropy), encrypted at rest (`encrypted` cast on the
 * model), and is meant to stay stable for the entire lifetime of the event:
 * bracelet signatures are verified by *recomputing* the HMAC, so rotating
 * the key would instantly invalidate every bracelet already printed with
 * the old one. Treat rotation as an exceptional, physically-consequential
 * operation (see rotate()), not routine key hygiene.
 */
class EventSecurityKeyService
{
    /**
     * Returns the event's signing secret, generating one on first use.
     * Safe to call concurrently: relies on the DB unique constraint on
     * event_id as the actual race guard, not on application-level locking.
     */
    public function getOrCreateSecret(int $eventId): string
    {
        $existing = DigitEventSecurityKey::query()
            ->where('event_id', $eventId)
            ->first();

        if ($existing !== null) {
            return $existing->secret;
        }

        $secret = $this->generateSecret();

        try {
            DigitEventSecurityKey::query()->create([
                'event_id' => $eventId,
                'secret' => $secret,
                'created_at' => now(),
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            // Unique constraint race: another process created it first.
            // Whatever it created is now the authoritative secret.
            if (!$this->isUniqueViolation($e)) {
                throw $e;
            }
        }

        return DigitEventSecurityKey::query()
            ->where('event_id', $eventId)
            ->firstOrFail()
            ->secret;
    }

    /**
     * Returns null (never generates) - used by read paths (lookup/export)
     * that must fail clearly if an operator forgot to initialize the key
     * before generating/printing bracelets, rather than silently creating
     * a brand new key that would never match already-printed QR codes.
     */
    public function findSecret(int $eventId): ?string
    {
        return DigitEventSecurityKey::query()
            ->where('event_id', $eventId)
            ->value('secret');
    }

    /**
     * Exceptional operation. Recorded with a mandatory reason via `notes`.
     * Invalidates every bracelet signature already computed with the old
     * secret - callers must only do this in response to a confirmed
     * compromise, and must plan to reprint the affected bracelets.
     */
    public function rotate(int $eventId, string $reason): string
    {
        $secret = $this->generateSecret();

        DB::transaction(function () use ($eventId, $secret, $reason) {
            DigitEventSecurityKey::query()
                ->where('event_id', $eventId)
                ->update([
                    'secret' => $secret,
                    'rotated_at' => now(),
                    'notes' => $reason,
                ]);
        });

        return $secret;
    }

    private function generateSecret(): string
    {
        return Str::random(64);
    }

    private function isUniqueViolation(\Illuminate\Database\QueryException $e): bool
    {
        return str_contains($e->getMessage(), 'digit_event_security_keys_event_id_unique')
            || (int) ($e->errorInfo[1] ?? 0) === 1062
            || ($e->getCode() === '23505');
    }
}
