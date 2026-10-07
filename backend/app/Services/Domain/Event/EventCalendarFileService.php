<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Event;

use Carbon\Carbon;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Helper\StringHelper;
use Spatie\IcalendarGenerator\Components\Calendar;
use Spatie\IcalendarGenerator\Components\Event;

class EventCalendarFileService
{
    public function ics(
        EventDomainObject $event,
        OrganizerDomainObject $organizer,
        EventSettingDomainObject $eventSettings,
        string $uniqueIdentifier,
    ): string {
        $timezone = $event->getTimezone() ?: 'UTC';

        $calendarEvent = Event::create()
            ->name($event->getTitle())
            ->uniqueIdentifier($uniqueIdentifier)
            ->startsAt(Carbon::parse($event->getStartDate(), 'UTC')->setTimezone($timezone))
            ->url($event->getEventUrl())
            ->organizer($organizer->getEmail(), $organizer->getName());

        if ($event->getDescription()) {
            $calendarEvent->description(StringHelper::previewFromHtml($event->getDescription()));
        }

        if ($eventSettings->getLocationDetails()) {
            $calendarEvent->address($eventSettings->getAddressString());
        }

        if ($event->getEndDate()) {
            $calendarEvent->endsAt(Carbon::parse($event->getEndDate(), 'UTC')->setTimezone($timezone));
        }

        return Calendar::create()->event($calendarEvent)->get();
    }
}
