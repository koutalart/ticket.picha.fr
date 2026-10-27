<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Social;

use Carbon\Carbon;
use HiEvents\DomainObjects\SocialFeedItemDomainObject;
use HiEvents\Repository\Interfaces\SocialFeedItemRepositoryInterface;
use HiEvents\Services\Domain\Social\Feed\SocialFeedSyncService;
use Illuminate\Support\Collection;

class GetSocialStoriesHandler
{
    private const MAX_STORIES = 100;

    public function __construct(
        private readonly SocialFeedItemRepositoryInterface $feedItemRepository,
    ) {}

    /**
     * @return Collection<int, SocialFeedItemDomainObject>
     */
    public function handle(int $organizerId): Collection
    {
        return $this->feedItemRepository->findStoriesForOrganizer(
            $organizerId,
            Carbon::now()->subDays(SocialFeedSyncService::STORY_RETENTION_DAYS),
            self::MAX_STORIES,
        );
    }
}
