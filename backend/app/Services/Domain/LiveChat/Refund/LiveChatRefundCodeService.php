<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\LiveChat\Refund;

use Carbon\Carbon;
use HiEvents\DomainObjects\LiveChatRefundRequestDomainObject;
use Illuminate\Contracts\Hashing\Hasher;

/**
 * The 6-digit code sent to the e-mail of the order. Only its hash is stored.
 */
class LiveChatRefundCodeService
{
    public const LENGTH = 6;

    public const VALIDITY_MINUTES = 15;

    public const MAX_ATTEMPTS = 5;

    public const MAX_SENDS_PER_ORDER_PER_DAY = 3;

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

    public function expiresAt(?Carbon $now = null): Carbon
    {
        return ($now ?? Carbon::now('UTC'))->copy()->addMinutes(self::VALIDITY_MINUTES);
    }

    public function isExpired(LiveChatRefundRequestDomainObject $request, ?Carbon $now = null): bool
    {
        $expiresAt = $request->getCodeExpiresAt();

        return $expiresAt === null || ($now ?? Carbon::now('UTC'))->greaterThanOrEqualTo(Carbon::parse($expiresAt, 'UTC'));
    }

    public function hasAttemptsLeft(LiveChatRefundRequestDomainObject $request): bool
    {
        return $request->getAttempts() < self::MAX_ATTEMPTS;
    }

    public function attemptsLeft(int $attempts): int
    {
        return max(0, self::MAX_ATTEMPTS - $attempts);
    }

    public function matches(LiveChatRefundRequestDomainObject $request, string $code): bool
    {
        $code = preg_replace('/\D/', '', $code) ?? '';
        $hash = $request->getCodeHash();

        return strlen($code) === self::LENGTH && $hash !== null && $this->hasher->check($code, $hash);
    }
}
