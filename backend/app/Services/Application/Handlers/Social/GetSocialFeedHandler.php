<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Social;

use HiEvents\Jobs\Social\SyncSocialFeedJob;
use HiEvents\Services\Domain\Social\Feed\DTO\SocialFeedEntryDTO;
use HiEvents\Services\Domain\Social\Feed\SocialFeedService;
use Illuminate\Support\Collection;

class GetSocialFeedHandler
{
    public function __construct(
        private readonly SocialFeedService $feedService,
    ) {}

    /**
     * The feed is read from the local copy; a synchronisation with the networks is queued at most once a minute.
     *
     * @return Collection<int, SocialFeedEntryDTO>
     */
    public function handle(int $organizerId, int $limit): Collection
    {
        SyncSocialFeedJob::dispatch($organizerId);

        return $this->feedService->entries($organizerId, $limit + 1);
    }
}
