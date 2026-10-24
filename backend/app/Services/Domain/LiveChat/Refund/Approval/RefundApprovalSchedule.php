<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\LiveChat\Refund\Approval;

use Carbon\Carbon;

/**
 * When the validators and the customer hear about a refund request waiting for approval. Validators get the request
 * right away, a reminder 2 hours later, then every 6 hours, only between 8:00 and 21:00 Mayotte time (a sending that
 * falls outside moves to 8:00). The customer gets news every 12 hours, moved to 8:00 at night too, so never more than
 * 24 hours without news. After 24 hours without decision, the platform supervision is alerted.
 */
final class RefundApprovalSchedule
{
    public const TIMEZONE = 'Indian/Mayotte';

    public const DAY_START_HOUR = 8;

    public const DAY_END_HOUR = 21;

    public const FIRST_REMINDER_HOURS = 2;

    public const REMINDER_INTERVAL_HOURS = 6;

    public const CUSTOMER_UPDATE_HOURS = 12;

    public const ESCALATION_HOURS = 24;

    public const LINK_VALIDITY_HOURS = 72;

    /**
     * The moment itself when it is between 8:00 and 21:00 in Mayotte, otherwise the next 8:00. Returned in UTC.
     */
    public static function withinDay(Carbon $moment): Carbon
    {
        $local = $moment->copy()->setTimezone(self::TIMEZONE);

        if ($local->hour < self::DAY_START_HOUR) {
            $local->setTime(self::DAY_START_HOUR, 0);
        } elseif ($local->hour >= self::DAY_END_HOUR) {
            $local->addDay()->setTime(self::DAY_START_HOUR, 0);
        }

        return $local->utc();
    }

    /**
     * The next notification to the validators after the given number of notifications was sent at $sentAt.
     */
    public static function nextReminderAt(Carbon $sentAt, int $notificationsSent): Carbon
    {
        $hours = $notificationsSent <= 1 ? self::FIRST_REMINDER_HOURS : self::REMINDER_INTERVAL_HOURS;

        return self::withinDay($sentAt->copy()->addHours($hours));
    }

    public static function nextCustomerUpdateAt(Carbon $sentAt): Carbon
    {
        return self::withinDay($sentAt->copy()->addHours(self::CUSTOMER_UPDATE_HOURS));
    }

    public static function escalationDueAt(Carbon $awaitingSince): Carbon
    {
        return $awaitingSince->copy()->addHours(self::ESCALATION_HOURS);
    }
}
