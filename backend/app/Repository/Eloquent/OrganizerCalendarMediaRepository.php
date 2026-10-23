<?php

declare(strict_types=1);

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\OrganizerCalendarMediaDomainObject;
use HiEvents\Models\OrganizerCalendarMedia;
use HiEvents\Repository\Interfaces\OrganizerCalendarMediaRepositoryInterface;

/**
 * @extends BaseRepository<OrganizerCalendarMediaDomainObject>
 */
class OrganizerCalendarMediaRepository extends BaseRepository implements OrganizerCalendarMediaRepositoryInterface
{
    protected function getModel(): string
    {
        return OrganizerCalendarMedia::class;
    }

    public function getDomainObject(): string
    {
        return OrganizerCalendarMediaDomainObject::class;
    }
}
