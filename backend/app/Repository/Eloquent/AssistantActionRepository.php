<?php

declare(strict_types=1);

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\AssistantActionDomainObject;
use HiEvents\Models\AssistantAction;
use HiEvents\Repository\Interfaces\AssistantActionRepositoryInterface;

/**
 * @extends BaseRepository<AssistantActionDomainObject>
 */
class AssistantActionRepository extends BaseRepository implements AssistantActionRepositoryInterface
{
    protected function getModel(): string
    {
        return AssistantAction::class;
    }

    public function getDomainObject(): string
    {
        return AssistantActionDomainObject::class;
    }
}
