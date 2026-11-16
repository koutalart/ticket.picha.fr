<?php

declare(strict_types=1);

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\EventViewerDomainObject;
use HiEvents\Models\EventViewer;
use HiEvents\Repository\Interfaces\EventViewerRepositoryInterface;

/**
 * @extends BaseRepository<EventViewerDomainObject>
 */
class EventViewerRepository extends BaseRepository implements EventViewerRepositoryInterface
{
    protected function getModel(): string
    {
        return EventViewer::class;
    }

    public function getDomainObject(): string
    {
        return EventViewerDomainObject::class;
    }
}
