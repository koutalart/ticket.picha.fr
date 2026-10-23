<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Attendee\AttendanceAccess;

use HiEvents\Exceptions\AttendanceAccessRateLimitedException;
use HiEvents\Helper\CustomerLocale;
use Illuminate\Cache\RateLimiter;

class AttendanceAccessRateLimiter
{
    public const MAX_REQUESTS_PER_CONTACT = 3;

    public const MAX_REQUESTS_PER_IP = 10;

    public const MAX_VERIFICATIONS_PER_IP = 30;

    public const DECAY_SECONDS = 3600;

    public function __construct(
        private readonly RateLimiter $rateLimiter,
    ) {}

    /**
     * Counts every request, whether or not the contact matches a registration.
     *
     * @throws AttendanceAccessRateLimitedException
     */
    public function hitCodeRequest(string $contactKey, string $ipAddress, ?string $locale = null): void
    {
        $ipKey = 'attendance-access:request-ip:'.hash('sha256', $ipAddress);
        $contactLimitKey = 'attendance-access:request-contact:'.$contactKey;

        if ($this->rateLimiter->tooManyAttempts($ipKey, self::MAX_REQUESTS_PER_IP)
            || $this->rateLimiter->tooManyAttempts($contactLimitKey, self::MAX_REQUESTS_PER_CONTACT)) {
            throw $this->limited($locale);
        }

        $this->rateLimiter->hit($ipKey, self::DECAY_SECONDS);
        $this->rateLimiter->hit($contactLimitKey, self::DECAY_SECONDS);
    }

    /**
     * @throws AttendanceAccessRateLimitedException
     */
    public function hitVerification(string $ipAddress, ?string $locale = null): void
    {
        $ipKey = 'attendance-access:verify-ip:'.hash('sha256', $ipAddress);

        if ($this->rateLimiter->tooManyAttempts($ipKey, self::MAX_VERIFICATIONS_PER_IP)) {
            throw $this->limited($locale);
        }

        $this->rateLimiter->hit($ipKey, self::DECAY_SECONDS);
    }

    private function limited(?string $locale): AttendanceAccessRateLimitedException
    {
        return new AttendanceAccessRateLimitedException(
            __('Too many attempts. Please try again in an hour.', [], $locale ?? CustomerLocale::get())
        );
    }
}
