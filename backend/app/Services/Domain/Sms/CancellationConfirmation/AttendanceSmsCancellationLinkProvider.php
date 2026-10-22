<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms\CancellationConfirmation;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\Services\Domain\Sms\Reminder\SmsReminderPersonalLinkProviderInterface;

/**
 * Until contacts have their own ?c= link, the attendee's personal attendance page is used, never the bare event page.
 */
class AttendanceSmsCancellationLinkProvider implements SmsCancellationLinkProviderInterface
{
    public function __construct(
        private readonly SmsReminderPersonalLinkProviderInterface $attendanceLinkProvider,
    ) {}

    public function findForAttendee(int $attendeeId, EventDomainObject $event): ?string
    {
        return $this->attendanceLinkProvider->findForAttendee($attendeeId);
    }
}
