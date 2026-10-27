<?php

declare(strict_types=1);

namespace HiEvents\Jobs\LiveChat;

use HiEvents\Services\Domain\LiveChat\Question\LiveChatAnswerRelayService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RelayLiveChatAnswerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly int $questionId,
    ) {
        $this->afterCommit = true;
    }

    public function handle(LiveChatAnswerRelayService $relayService): void
    {
        $relayService->relay($this->questionId);
    }
}
