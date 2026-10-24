<?php

declare(strict_types=1);

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\OrganizerSocialConnectionDomainObject;
use HiEvents\Models\OrganizerSocialConnection;
use HiEvents\Repository\Interfaces\OrganizerSocialConnectionRepositoryInterface;

/**
 * @extends BaseRepository<OrganizerSocialConnectionDomainObject>
 */
class OrganizerSocialConnectionRepository extends BaseRepository implements OrganizerSocialConnectionRepositoryInterface
{
    protected function getModel(): string
    {
        return OrganizerSocialConnection::class;
    }

    public function getDomainObject(): string
    {
        return OrganizerSocialConnectionDomainObject::class;
    }
}
