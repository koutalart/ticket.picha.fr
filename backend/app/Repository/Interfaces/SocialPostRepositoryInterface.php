<?php

declare(strict_types=1);

namespace HiEvents\Repository\Interfaces;

use Carbon\Carbon;
use HiEvents\DomainObjects\SocialPostDomainObject;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * @extends RepositoryInterface<SocialPostDomainObject>
 */
interface SocialPostRepositoryInterface extends RepositoryInterface
{
    /**
     * Scheduled posts whose time has come, oldest first.
     *
     * @return Collection<int, SocialPostDomainObject>
     */
    public function findDueForPublishing(int $limit): Collection;

    /**
     * Moves a scheduled post to PUBLISHING. Returns false when another worker already took it.
     */
    public function claimForPublishing(int $postId): bool;

    /**
     * Puts back in the queue the posts left in PUBLISHING by a worker that stopped before finishing.
     */
    public function releaseStalePublishing(Carbon $updatedBefore): int;

    public function paginateForOrganizer(int $organizerId, ?string $status, int $perPage): LengthAwarePaginator;
}
