<?php

declare(strict_types=1);

namespace Digit\Devices\Domain\Services;

use Digit\Devices\Domain\Exceptions\ActivationCodeInvalidException;
use Illuminate\Support\Facades\DB;

/**
 * Generates and redeems short-lived, single-use activation codes for
 * DIGIT scan devices, so a field agent types a short human code instead
 * of pasting a 48-character device token. The code is hashed in storage
 * (sha256) exactly like the device token itself - never stored plain.
 * This service never reads or exposes the device's real token; a future
 * caller (HTTP action, not built in this step) is responsible for that
 * once redeem() confirms validity. No public route wired yet.
 */
class DeviceActivationCodeService
{
    private const CODE_LENGTH = 8;
    private const TTL_MINUTES = 15;

    public function generate(int $deviceId): string
    {
        return DB::transaction(function () use ($deviceId) {
            // Invalidate any previous unused code for this device.
            DB::table('digit_scan_device_activation_codes')
                ->where('device_id', $deviceId)
                ->whereNull('used_at')
                ->update(['used_at' => now(), 'updated_at' => now()]);

            $plainCode = $this->generateHumanReadableCode();

            DB::table('digit_scan_device_activation_codes')->insert([
                'device_id' => $deviceId,
                'code' => hash('sha256', $plainCode),
                'expires_at' => now()->addMinutes(self::TTL_MINUTES),
                'used_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $plainCode;
        });
    }

    public function redeem(string $plainCode): int
    {
        $codeHash = hash('sha256', $plainCode);

        return DB::transaction(function () use ($codeHash) {
            $record = DB::table('digit_scan_device_activation_codes')
                ->where('code', $codeHash)
                ->lockForUpdate()
                ->first();

            if ($record === null) {
                throw new ActivationCodeInvalidException('unknown_code');
            }

            if ($record->used_at !== null) {
                throw new ActivationCodeInvalidException('already_used');
            }

            if (now()->greaterThan($record->expires_at)) {
                throw new ActivationCodeInvalidException('expired');
            }

            DB::table('digit_scan_device_activation_codes')
                ->where('id', $record->id)
                ->update(['used_at' => now(), 'updated_at' => now()]);

            return (int) $record->device_id;
        });
    }

    private function generateHumanReadableCode(): string
    {
        // Excludes visually ambiguous characters (0/O, 1/I/L) for terrain readability.
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $code = '';

        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $code;
    }
}
