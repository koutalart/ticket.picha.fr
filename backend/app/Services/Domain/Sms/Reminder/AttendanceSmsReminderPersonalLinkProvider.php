<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms\Reminder;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\EventSettingsRepositoryInterface;
use HiEvents\Services\Domain\Attendee\AttendeeAttendanceLinkGenerator;

class AttendanceSmsReminderPersonalLinkProvider implements SmsReminderPersonalLinkProviderInterface
{
    public function __construct(
        private readonly AttendeeRepositoryInterface      $attendeeRepository,
        private readonly EventSettingsRepositoryInterface $eventSettingsRepository,
        private readonly AttendeeAttendanceLinkGenerator  $linkGenerator,
    )
    {
    }

    public function findForAttendee(int $attendeeId): ?string
    {
        /** @var AttendeeDomainObject|null $attendee */
        $attendee = $this->attendeeRepository->findFirst($attendeeId);
        if ($attendee === null) {
            return null;
        }

        /** @var EventSettingDomainObject|null $settings */
        $settings = $this->eventSettingsRepository->findFirstWhere(['event_id' => $attendee->getEventId()]);
        if ($settings === null || !$settings->getAllowAttendeeAttendanceResponse()) {
            return null;
        }

        return $this->linkGenerator->getAttendanceUrl($attendee);
    }
}
