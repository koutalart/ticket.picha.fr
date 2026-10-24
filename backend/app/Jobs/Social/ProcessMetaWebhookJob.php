<?php

declare(strict_types=1);

namespace HiEvents\Jobs\Social;

use HiEvents\Services\Domain\Social\Feed\MetaWebhookService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessMetaWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly array $payload) {}

    public function handle(MetaWebhookService $webhookService): void
    {
        $webhookService->handle($this->payload);
    }
}
