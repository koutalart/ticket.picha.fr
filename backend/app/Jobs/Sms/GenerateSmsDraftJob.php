<?php

declare(strict_types=1);

namespace HiEvents\Jobs\Sms;

use HiEvents\Exceptions\SmsAssistantFailedException;
use HiEvents\Exceptions\SmsAssistantLimitReachedException;
use HiEvents\Exceptions\SmsAssistantUnavailableException;
use HiEvents\Services\Domain\Sms\SmsAssistantService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Psr\Log\LoggerInterface;

class GenerateSmsDraftJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(
        public readonly int $conversationId,
        public readonly int $messageId,
    ) {}

    public function handle(SmsAssistantService $assistantService, LoggerInterface $logger): void
    {
        try {
            $assistantService->draftForInboundMessage($this->conversationId, $this->messageId);
        } catch (SmsAssistantUnavailableException|SmsAssistantLimitReachedException) {
            return;
        } catch (SmsAssistantFailedException) {
            $logger->warning('SMS assistant: no draft for this message', [
                'sms_conversation_id' => $this->conversationId,
                'sms_message_id' => $this->messageId,
            ]);
        }
    }
}
