<?php

declare(strict_types=1);

namespace HiEvents\Repository\Interfaces;

use HiEvents\DomainObjects\LiveChatMessageDomainObject;
use Illuminate\Support\Collection;

/**
 * @extends RepositoryInterface<LiveChatMessageDomainObject>
 */
interface LiveChatMessageRepositoryInterface extends RepositoryInterface
{
    /**
     * @return Collection<int, LiveChatMessageDomainObject> oldest first
     */
    public function findLatestForSession(int $sessionId, int $limit): Collection;
}
