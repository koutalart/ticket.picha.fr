<?php

declare(strict_types=1);

namespace HiEvents\Jobs\Social;

use HiEvents\DomainObjects\Generated\OrganizerSocialConnectionDomainObjectAbstract;
use HiEvents\DomainObjects\Status\SocialConnectionStatus;
use HiEvents\Repository\Interfaces\OrganizerSocialConnectionRepositoryInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Fallback when a webhook is missed: every connected organizer is synchronised every few minutes.
 */
class SyncAllSocialFeedsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(OrganizerSocialConnectionRepositoryInterface $connectionRepository): void
    {
        $connections = $connectionRepository->findWhere([
            OrganizerSocialConnectionDomainObjectAbstract::STATUS => SocialConnectionStatus::ACTIVE->name,
        ]);

        foreach ($connections as $connection) {
            SyncSocialFeedJob::dispatch($connection->getOrganizerId());
        }
    }
}
