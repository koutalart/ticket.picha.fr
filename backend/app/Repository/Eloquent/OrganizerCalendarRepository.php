<?php

declare(strict_types=1);

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\OrganizerCalendarDomainObject;
use HiEvents\Models\OrganizerCalendar;
use HiEvents\Repository\Interfaces\OrganizerCalendarRepositoryInterface;

/**
 * @extends BaseRepository<OrganizerCalendarDomainObject>
 */
class OrganizerCalendarRepository extends BaseRepository implements OrganizerCalendarRepositoryInterface
{
    protected function getModel(): string
    {
        return OrganizerCalendar::class;
    }

    public function getDomainObject(): string
    {
        return OrganizerCalendarDomainObject::class;
    }
}
