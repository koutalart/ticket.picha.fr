<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms\Reminder;

/**
 * Used until attendees have a personal confirmation link: {lien} is then removed from the message.
 */
class NullSmsReminderPersonalLinkProvider implements SmsReminderPersonalLinkProviderInterface
{
    public function findForAttendee(int $attendeeId): ?string
    {
        return null;
    }
}
