<?php

declare(strict_types=1);

namespace HiEvents\Jobs\Social;

use HiEvents\Repository\Interfaces\SocialPostRepositoryInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PublishDueSocialPostsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const BATCH_SIZE = 20;

    private const STALE_PUBLISHING_MINUTES = 15;

    public function handle(SocialPostRepositoryInterface $postRepository): void
    {
        $postRepository->releaseStalePublishing(now()->subMinutes(self::STALE_PUBLISHING_MINUTES));

        foreach ($postRepository->findDueForPublishing(self::BATCH_SIZE) as $post) {
            if ($postRepository->claimForPublishing($post->getId())) {
                PublishSocialPostJob::dispatch($post->getId());
            }
        }
    }
}
