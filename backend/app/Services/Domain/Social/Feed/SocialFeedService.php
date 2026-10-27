<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Social\Feed;

use Carbon\Carbon;
use HiEvents\DomainObjects\SocialFeedItemDomainObject;
use HiEvents\DomainObjects\SocialPostDomainObject;
use HiEvents\Repository\Interfaces\SocialFeedItemRepositoryInterface;
use HiEvents\Repository\Interfaces\SocialPostRepositoryInterface;
use HiEvents\Services\Domain\Social\Feed\DTO\SocialFeedEntryDTO;
use Illuminate\Support\Collection;

/**
 * Merges the posts prepared in PICHA and the posts read on the networks, most recent (or furthest scheduled) first.
 */
class SocialFeedService
{
    public const MAX_ENTRIES = 200;

    public function __construct(
        private readonly SocialPostRepositoryInterface $postRepository,
        private readonly SocialFeedItemRepositoryInterface $feedItemRepository,
    ) {}

    /**
     * @return Collection<int, SocialFeedEntryDTO>
     */
    public function entries(int $organizerId, int $limit): Collection
    {
        $limit = max(1, min($limit, self::MAX_ENTRIES));
        $items = $this->feedItemRepository->findLatestForOrganizer($organizerId, $limit + 1);
        $posts = $this->postRepository->paginateForOrganizer($organizerId, null, $limit + 1)->getCollection();
        $postsById = $posts->keyBy(fn (SocialPostDomainObject $post) => $post->getId());
        $linkedPostIds = $items->map(fn (SocialFeedItemDomainObject $item) => $item->getSocialPostId())->filter()->all();

        $entries = $items->map(fn (SocialFeedItemDomainObject $item) => new SocialFeedEntryDTO(
            date: Carbon::parse($item->getPublishedAt() ?? $item->getCreatedAt()),
            post: $item->getSocialPostId() ? $postsById->get($item->getSocialPostId()) : null,
            item: $item,
        ));

        $pichaOnly = $posts
            ->reject(fn (SocialPostDomainObject $post) => in_array($post->getId(), $linkedPostIds, true))
            ->map(fn (SocialPostDomainObject $post) => new SocialFeedEntryDTO(
                date: Carbon::parse($post->getPublishedAt() ?? $post->getScheduledAt() ?? $post->getCreatedAt()),
                post: $post,
            ));

        return $entries->concat($pichaOnly)
            ->sortByDesc(fn (SocialFeedEntryDTO $entry) => $entry->date->getTimestamp())
            ->values();
    }
}
