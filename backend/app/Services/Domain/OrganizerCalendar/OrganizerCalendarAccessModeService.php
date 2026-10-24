<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\OrganizerCalendar;

use HiEvents\DomainObjects\Enums\OrganizerCalendarAccessMode;
use HiEvents\DomainObjects\Generated\OrganizerCalendarDomainObjectAbstract as Calendar;
use HiEvents\DomainObjects\OrganizerCalendarDomainObject;
use HiEvents\Repository\Interfaces\OrganizerCalendarRepositoryInterface;

/**
 * "name": the visitor types a first name and enters, no SMS is ever sent (default).
 * "code": a one-time SMS code sent to the calendar phone number.
 */
class OrganizerCalendarAccessModeService
{
    public function __construct(
        private readonly OrganizerCalendarRepositoryInterface $calendarRepository,
    ) {}

    public static function modeOf(OrganizerCalendarDomainObject $calendar): OrganizerCalendarAccessMode
    {
        return OrganizerCalendarAccessMode::tryFrom((string) $calendar->getAccessMode()) ?? OrganizerCalendarAccessMode::NAME;
    }

    public function setMode(OrganizerCalendarDomainObject $calendar, OrganizerCalendarAccessMode $mode): void
    {
        $this->calendarRepository->updateWhere(
            [Calendar::ACCESS_MODE => $mode->value],
            [Calendar::ID => $calendar->getId()],
        );
    }
}
