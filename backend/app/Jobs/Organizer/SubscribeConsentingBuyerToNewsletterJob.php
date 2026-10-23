<?php

declare(strict_types=1);

namespace HiEvents\Jobs\Organizer;

use HiEvents\Services\Domain\Organizer\NewsletterConsentSubscriptionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class SubscribeConsentingBuyerToNewsletterJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(public readonly int $orderId) {}

    public function handle(NewsletterConsentSubscriptionService $service): void
    {
        $service->subscribeFromOrder($this->orderId);
    }
}
