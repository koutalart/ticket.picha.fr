<?php

declare(strict_types=1);

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\EventAssistantFactDomainObject;
use HiEvents\Models\EventAssistantFact;
use HiEvents\Repository\Interfaces\EventAssistantFactRepositoryInterface;

/**
 * @extends BaseRepository<EventAssistantFactDomainObject>
 */
class EventAssistantFactRepository extends BaseRepository implements EventAssistantFactRepositoryInterface
{
    protected function getModel(): string
    {
        return EventAssistantFact::class;
    }

    public function getDomainObject(): string
    {
        return EventAssistantFactDomainObject::class;
    }
}
