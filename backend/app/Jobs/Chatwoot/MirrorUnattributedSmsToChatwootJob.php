<?php

declare(strict_types=1);

namespace HiEvents\Jobs\Chatwoot;

use HiEvents\Services\Domain\Chatwoot\ChatwootSmsMirrorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class MirrorUnattributedSmsToChatwootJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public array $backoff = [10, 30, 120, 600];

    public function __construct(public readonly int $unattributedId)
    {
        $this->afterCommit = true;
    }

    public function handle(ChatwootSmsMirrorService $mirrorService): void
    {
        $mirrorService->mirrorUnattributed($this->unattributedId);
    }
}
