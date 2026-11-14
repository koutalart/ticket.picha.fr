<?php

declare(strict_types=1);

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\EventPresenceDomainObject;
use HiEvents\Models\EventPresence;
use HiEvents\Repository\Interfaces\EventPresenceRepositoryInterface;

/**
 * @extends BaseRepository<EventPresenceDomainObject>
 */
class EventPresenceRepository extends BaseRepository implements EventPresenceRepositoryInterface
{
    protected function getModel(): string
    {
        return EventPresence::class;
    }

    public function getDomainObject(): string
    {
        return EventPresenceDomainObject::class;
    }
}
