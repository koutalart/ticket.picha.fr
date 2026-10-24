<?php

declare(strict_types=1);

namespace HiEvents\Jobs\Social;

use HiEvents\Services\Domain\Social\Feed\SocialFeedSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncSocialFeedJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public int $uniqueFor = 60;

    public function __construct(public readonly int $organizerId) {}

    public function uniqueId(): string
    {
        return (string) $this->organizerId;
    }

    public function handle(SocialFeedSyncService $syncService): void
    {
        $syncService->syncOrganizer($this->organizerId);
    }
}
