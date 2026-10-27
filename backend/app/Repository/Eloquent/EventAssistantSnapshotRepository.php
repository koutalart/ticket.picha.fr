<?php

declare(strict_types=1);

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\EventAssistantSnapshotDomainObject;
use HiEvents\Models\EventAssistantSnapshot;
use HiEvents\Repository\Interfaces\EventAssistantSnapshotRepositoryInterface;

/**
 * @extends BaseRepository<EventAssistantSnapshotDomainObject>
 */
class EventAssistantSnapshotRepository extends BaseRepository implements EventAssistantSnapshotRepositoryInterface
{
    protected function getModel(): string
    {
        return EventAssistantSnapshot::class;
    }

    public function getDomainObject(): string
    {
        return EventAssistantSnapshotDomainObject::class;
    }
}
