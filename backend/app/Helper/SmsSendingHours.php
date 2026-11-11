<?php

declare(strict_types=1);

namespace HiEvents\Helper;

use Illuminate\Support\Carbon;

/**
 * Automatic SMS only leave between 8:00 and 20:00 in the event timezone.
 */
final class SmsSendingHours
{
    public const FIRST_HOUR = 8;

    public const LAST_HOUR = 20;

    /**
     * Null when sending now is allowed, otherwise the next moment it is (UTC).
     */
    public static function nextAllowedTime(Carbon $now, string $timezone): ?Carbon
    {
        $local = $now->copy()->setTimezone($timezone);

        if ($local->hour >= self::FIRST_HOUR && $local->hour < self::LAST_HOUR) {
            return null;
        }

        $next = $local->copy()->setTime(self::FIRST_HOUR, 0);
        if ($local->hour >= self::LAST_HOUR) {
            $next->addDay();
        }

        return $next->setTimezone('UTC');
    }
}
