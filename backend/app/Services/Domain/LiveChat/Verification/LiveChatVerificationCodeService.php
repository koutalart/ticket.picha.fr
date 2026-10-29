<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\LiveChat\Verification;

use Carbon\Carbon;
use Illuminate\Contracts\Hashing\Hasher;

/**
 * The 6-digit codes of the web chat (refund by e-mail, ticket resend by SMS). Only their hash is stored.
 */
class LiveChatVerificationCodeService
{
    public const LENGTH = 6;

    public function __construct(
        private readonly Hasher $hasher,
    ) {}

    public function generate(): string
    {
        return str_pad((string) random_int(0, 10 ** self::LENGTH - 1), self::LENGTH, '0', STR_PAD_LEFT);
    }

    public function hash(string $code): string
    {
        return $this->hasher->make($code);
    }

    public function expiresAt(int $validityMinutes, ?Carbon $now = null): Carbon
    {
        return ($now ?? Carbon::now('UTC'))->copy()->addMinutes($validityMinutes);
    }

    public function isExpired(?string $expiresAt, ?Carbon $now = null): bool
    {
        return $expiresAt === null || ($now ?? Carbon::now('UTC'))->greaterThanOrEqualTo(Carbon::parse($expiresAt, 'UTC'));
    }

    public function matches(?string $hash, string $code): bool
    {
        $code = preg_replace('/\D/', '', $code) ?? '';

        return strlen($code) === self::LENGTH && $hash !== null && $this->hasher->check($code, $hash);
    }

    public static function attemptsLeft(int $maxAttempts, int $attempts): int
    {
        return max(0, $maxAttempts - $attempts);
    }
}
