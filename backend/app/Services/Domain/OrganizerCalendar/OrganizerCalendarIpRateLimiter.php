<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\OrganizerCalendar;

use HiEvents\Exceptions\OrganizerCalendarRateLimitedException;
use HiEvents\Helper\CustomerLocale;
use Illuminate\Cache\RateLimiter;

class OrganizerCalendarIpRateLimiter
{
    public const MAX_CODE_REQUESTS_PER_IP = 10;

    public const MAX_VERIFICATIONS_PER_IP = 20;

    public const MAX_ADMIN_FAILURES_PER_IP = 5;

    public const MAX_CONSULTATIONS_PER_IP = 30;

    public const DECAY_SECONDS = 3600;

    public function __construct(
        private readonly RateLimiter $rateLimiter,
    ) {}

    /**
     * @throws OrganizerCalendarRateLimitedException
     */
    public function hitCodeRequest(string $ipAddress): void
    {
        $this->hit('organizer-calendar:code-ip:'.hash('sha256', $ipAddress), self::MAX_CODE_REQUESTS_PER_IP);
    }

    /**
     * @throws OrganizerCalendarRateLimitedException
     */
    public function hitVerification(string $ipAddress): void
    {
        $this->hit('organizer-calendar:verify-ip:'.hash('sha256', $ipAddress), self::MAX_VERIFICATIONS_PER_IP);
    }

    /**
     * @throws OrganizerCalendarRateLimitedException
     */
    public function hitConsultation(string $ipAddress): void
    {
        $this->hit('organizer-calendar:consult-ip:'.hash('sha256', $ipAddress), self::MAX_CONSULTATIONS_PER_IP);
    }

    /**
     * Admin codes: only failed attempts count, at most 5 per hour per IP.
     *
     * @throws OrganizerCalendarRateLimitedException
     */
    public function assertAdminAttemptAllowed(string $ipAddress): void
    {
        $key = self::adminKey($ipAddress);

        if ($this->rateLimiter->tooManyAttempts($key, self::MAX_ADMIN_FAILURES_PER_IP)) {
            throw $this->limited($key);
        }
    }

    public function hitAdminFailure(string $ipAddress): void
    {
        $this->rateLimiter->hit(self::adminKey($ipAddress), self::DECAY_SECONDS);
    }

    private static function adminKey(string $ipAddress): string
    {
        return 'organizer-calendar:admin-ip:'.hash('sha256', $ipAddress);
    }

    /**
     * @throws OrganizerCalendarRateLimitedException
     */
    private function hit(string $key, int $maxAttempts): void
    {
        if ($this->rateLimiter->tooManyAttempts($key, $maxAttempts)) {
            throw $this->limited($key);
        }

        $this->rateLimiter->hit($key, self::DECAY_SECONDS);
    }

    private function limited(string $key): OrganizerCalendarRateLimitedException
    {
        $retryAfter = max(1, $this->rateLimiter->availableIn($key));

        return new OrganizerCalendarRateLimitedException(
            __('Too many attempts. Try again in :delay.', ['delay' => OrganizerCalendarCodeService::formatDelay($retryAfter)], CustomerLocale::get()),
            $retryAfter,
        );
    }
}
