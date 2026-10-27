<?php

declare(strict_types=1);

namespace HiEvents\Repository\Interfaces;

use HiEvents\DomainObjects\SocialFeedItemDomainObject;
use Illuminate\Support\Collection;

/**
 * @extends RepositoryInterface<SocialFeedItemDomainObject>
 */
interface SocialFeedItemRepositoryInterface extends RepositoryInterface
{
    public function upsertFromNetwork(int $organizerId, string $network, string $externalId, array $attributes): SocialFeedItemDomainObject;

    /**
     * Most recent first.
     *
     * @return Collection<int, SocialFeedItemDomainObject>
     */
    public function findLatestForOrganizer(int $organizerId, int $limit): Collection;
}
