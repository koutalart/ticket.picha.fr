<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\LiveChat\Refund;

use Carbon\Carbon;
use HiEvents\DomainObjects\LiveChatRefundRequestDomainObject;
use HiEvents\Services\Domain\LiveChat\Verification\LiveChatVerificationCodeService;

/**
 * The 6-digit code sent to the e-mail of the order. Only its hash is stored.
 */
class LiveChatRefundCodeService
{
    public const LENGTH = LiveChatVerificationCodeService::LENGTH;

    public const VALIDITY_MINUTES = 15;

    public const MAX_ATTEMPTS = 5;

    public const MAX_SENDS_PER_ORDER_PER_DAY = 3;

    public function __construct(
        private readonly LiveChatVerificationCodeService $codes,
    ) {}

    public function generate(): string
    {
        return $this->codes->generate();
    }

    public function hash(string $code): string
    {
        return $this->codes->hash($code);
    }

    public function expiresAt(?Carbon $now = null): Carbon
    {
        return $this->codes->expiresAt(self::VALIDITY_MINUTES, $now);
    }

    public function isExpired(LiveChatRefundRequestDomainObject $request, ?Carbon $now = null): bool
    {
        return $this->codes->isExpired($request->getCodeExpiresAt(), $now);
    }

    public function hasAttemptsLeft(LiveChatRefundRequestDomainObject $request): bool
    {
        return $request->getAttempts() < self::MAX_ATTEMPTS;
    }

    public function attemptsLeft(int $attempts): int
    {
        return LiveChatVerificationCodeService::attemptsLeft(self::MAX_ATTEMPTS, $attempts);
    }

    public function matches(LiveChatRefundRequestDomainObject $request, string $code): bool
    {
        return $this->codes->matches($request->getCodeHash(), $code);
    }
}
