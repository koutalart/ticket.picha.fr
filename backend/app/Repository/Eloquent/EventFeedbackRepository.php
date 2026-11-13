<?php

declare(strict_types=1);

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\EventFeedbackDomainObject;
use HiEvents\Models\EventFeedback;
use HiEvents\Repository\Interfaces\EventFeedbackRepositoryInterface;

/**
 * @extends BaseRepository<EventFeedbackDomainObject>
 */
class EventFeedbackRepository extends BaseRepository implements EventFeedbackRepositoryInterface
{
    protected function getModel(): string
    {
        return EventFeedback::class;
    }

    public function getDomainObject(): string
    {
        return EventFeedbackDomainObject::class;
    }
}
