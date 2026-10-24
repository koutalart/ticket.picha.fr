<?php

declare(strict_types=1);

namespace HiEvents\Repository\Interfaces;

use HiEvents\DomainObjects\LiveChatSessionDomainObject;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * @extends RepositoryInterface<LiveChatSessionDomainObject>
 */
interface LiveChatSessionRepositoryInterface extends RepositoryInterface
{
    public function paginateForOrganizer(int $organizerId, int $page, int $perPage): LengthAwarePaginator;

    /**
     * Deletes the chats where the visitor left no phone nor email and that are older than the given date.
     */
    public function deleteWithoutContactBefore(string $before): int;
}
