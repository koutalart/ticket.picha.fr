<?php

declare(strict_types=1);

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\OrganizerTeamContactDomainObject;
use HiEvents\Models\OrganizerTeamContact;
use HiEvents\Repository\Interfaces\OrganizerTeamContactRepositoryInterface;

/**
 * @extends BaseRepository<OrganizerTeamContactDomainObject>
 */
class OrganizerTeamContactRepository extends BaseRepository implements OrganizerTeamContactRepositoryInterface
{
    protected function getModel(): string
    {
        return OrganizerTeamContact::class;
    }

    public function getDomainObject(): string
    {
        return OrganizerTeamContactDomainObject::class;
    }
}
