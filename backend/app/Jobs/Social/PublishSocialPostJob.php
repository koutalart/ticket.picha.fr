<?php

declare(strict_types=1);

namespace HiEvents\Jobs\Social;

use HiEvents\Services\Domain\Social\SocialPublishingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PublishSocialPostJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public readonly int $postId) {}

    public function handle(SocialPublishingService $publishingService): void
    {
        $publishingService->publish($this->postId);
    }
}
