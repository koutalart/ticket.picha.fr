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
    private function hit(string $key, int $maxAttempts): void
    {
        if ($this->rateLimiter->tooManyAttempts($key, $maxAttempts)) {
            $retryAfter = max(1, $this->rateLimiter->availableIn($key));

            throw new OrganizerCalendarRateLimitedException(
                __('Too many attempts. Try again in :delay.', ['delay' => OrganizerCalendarCodeService::formatDelay($retryAfter)], CustomerLocale::get()),
                $retryAfter,
            );
        }

        $this->rateLimiter->hit($key, self::DECAY_SECONDS);
    }
}
