<?php

declare(strict_types=1);

namespace HiEvents\Repository\Interfaces;

use Carbon\CarbonInterface;
use HiEvents\DomainObjects\SocialFeedItemDomainObject;
use Illuminate\Support\Collection;

/**
 * @extends RepositoryInterface<SocialFeedItemDomainObject>
 */
interface SocialFeedItemRepositoryInterface extends RepositoryInterface
{
    public function upsertFromNetwork(int $organizerId, string $network, string $externalId, array $attributes): SocialFeedItemDomainObject;

    /**
     * Most recent first, stories excluded.
     *
     * @return Collection<int, SocialFeedItemDomainObject>
     */
    public function findLatestForOrganizer(int $organizerId, int $limit): Collection;

    /**
     * Stories published since the given date, most recent first.
     *
     * @return Collection<int, SocialFeedItemDomainObject>
     */
    public function findStoriesForOrganizer(int $organizerId, CarbonInterface $since, int $limit): Collection;
}
