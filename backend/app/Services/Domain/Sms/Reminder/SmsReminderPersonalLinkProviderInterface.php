<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms\Reminder;

/**
 * Personal link (confirm or cancel attendance) inserted in a reminder through the {lien} variable.
 */
interface SmsReminderPersonalLinkProviderInterface
{
    public function findForAttendee(int $attendeeId): ?string;
}
