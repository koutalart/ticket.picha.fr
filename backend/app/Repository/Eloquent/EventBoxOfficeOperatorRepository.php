<?php

declare(strict_types=1);

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\EventBoxOfficeOperatorDomainObject;
use HiEvents\Models\EventBoxOfficeOperator;
use HiEvents\Repository\Interfaces\EventBoxOfficeOperatorRepositoryInterface;

/**
 * @extends BaseRepository<EventBoxOfficeOperatorDomainObject>
 */
class EventBoxOfficeOperatorRepository extends BaseRepository implements EventBoxOfficeOperatorRepositoryInterface
{
    protected function getModel(): string
    {
        return EventBoxOfficeOperator::class;
    }

    public function getDomainObject(): string
    {
        return EventBoxOfficeOperatorDomainObject::class;
    }
}
