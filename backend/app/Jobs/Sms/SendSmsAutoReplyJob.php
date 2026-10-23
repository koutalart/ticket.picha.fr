<?php

declare(strict_types=1);

namespace HiEvents\Jobs\Sms;

use HiEvents\Services\Application\Handlers\Sms\SendSmsAutoReplyHandler;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendSmsAutoReplyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(
        public readonly int $conversationId,
        public readonly int $messageId,
        public readonly ?string $answer,
        public readonly ?string $holdingReply,
    ) {}

    public function handle(SendSmsAutoReplyHandler $handler): void
    {
        $handler->handle($this->conversationId, $this->messageId, $this->answer, $this->holdingReply);
    }
}
