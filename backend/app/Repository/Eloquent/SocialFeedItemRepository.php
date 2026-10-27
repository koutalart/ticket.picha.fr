<?php

declare(strict_types=1);

namespace HiEvents\Repository\Eloquent;

use Carbon\CarbonInterface;
use HiEvents\DomainObjects\Generated\SocialFeedItemDomainObjectAbstract;
use HiEvents\DomainObjects\SocialFeedItemDomainObject;
use HiEvents\Models\SocialFeedItem;
use HiEvents\Repository\Interfaces\SocialFeedItemRepositoryInterface;
use Illuminate\Support\Collection;

/**
 * @extends BaseRepository<SocialFeedItemDomainObject>
 */
class SocialFeedItemRepository extends BaseRepository implements SocialFeedItemRepositoryInterface
{
    private const STORY = 'STORY';

    protected function getModel(): string
    {
        return SocialFeedItem::class;
    }

    public function getDomainObject(): string
    {
        return SocialFeedItemDomainObject::class;
    }

    public function upsertFromNetwork(int $organizerId, string $network, string $externalId, array $attributes): SocialFeedItemDomainObject
    {
        $model = SocialFeedItem::query()->updateOrCreate([
            SocialFeedItemDomainObjectAbstract::ORGANIZER_ID => $organizerId,
            SocialFeedItemDomainObjectAbstract::NETWORK => $network,
            SocialFeedItemDomainObjectAbstract::EXTERNAL_ID => $externalId,
        ], $attributes);

        return $this->handleSingleResult($model);
    }

    public function findLatestForOrganizer(int $organizerId, int $limit): Collection
    {
        return $this->handleResults(SocialFeedItem::query()
            ->where(SocialFeedItemDomainObjectAbstract::ORGANIZER_ID, $organizerId)
            ->where(function ($query) {
                $query->whereNull(SocialFeedItemDomainObjectAbstract::MEDIA_PRODUCT_TYPE)
                    ->orWhere(SocialFeedItemDomainObjectAbstract::MEDIA_PRODUCT_TYPE, '!=', self::STORY);
            })
            ->orderByDesc(SocialFeedItemDomainObjectAbstract::PUBLISHED_AT)
            ->orderByDesc(SocialFeedItemDomainObjectAbstract::ID)
            ->limit($limit)
            ->get());
    }

    public function findStoriesForOrganizer(int $organizerId, CarbonInterface $since, int $limit): Collection
    {
        return $this->handleResults(SocialFeedItem::query()
            ->where(SocialFeedItemDomainObjectAbstract::ORGANIZER_ID, $organizerId)
            ->where(SocialFeedItemDomainObjectAbstract::MEDIA_PRODUCT_TYPE, self::STORY)
            ->where(SocialFeedItemDomainObjectAbstract::PUBLISHED_AT, '>=', $since)
            ->orderByDesc(SocialFeedItemDomainObjectAbstract::PUBLISHED_AT)
            ->orderByDesc(SocialFeedItemDomainObjectAbstract::ID)
            ->limit($limit)
            ->get());
    }
}
