<?php

declare(strict_types=1);

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\OrganizerCalendarAccessDomainObject;
use HiEvents\Models\OrganizerCalendarAccess;
use HiEvents\Repository\Interfaces\OrganizerCalendarAccessRepositoryInterface;

/**
 * @extends BaseRepository<OrganizerCalendarAccessDomainObject>
 */
class OrganizerCalendarAccessRepository extends BaseRepository implements OrganizerCalendarAccessRepositoryInterface
{
    protected function getModel(): string
    {
        return OrganizerCalendarAccess::class;
    }

    public function getDomainObject(): string
    {
        return OrganizerCalendarAccessDomainObject::class;
    }
}
