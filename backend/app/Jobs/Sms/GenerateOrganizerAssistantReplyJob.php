<?php

declare(strict_types=1);

namespace HiEvents\Jobs\Sms;

use HiEvents\Exceptions\SmsAssistantFailedException;
use HiEvents\Exceptions\SmsAssistantUnavailableException;
use HiEvents\Services\Domain\Sms\OrganizerAssistant\OrganizerAssistantService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Psr\Log\LoggerInterface;

class GenerateOrganizerAssistantReplyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(
        public readonly int $conversationId,
        public readonly int $messageId,
    ) {}

    public function handle(OrganizerAssistantService $assistantService, LoggerInterface $logger): void
    {
        try {
            $assistantService->replyToInbound($this->conversationId, $this->messageId);
        } catch (SmsAssistantUnavailableException) {
            return;
        } catch (SmsAssistantFailedException) {
            $logger->warning('Organizer assistant: no reply for this message', [
                'sms_conversation_id' => $this->conversationId,
                'sms_message_id' => $this->messageId,
            ]);
        }
    }
}
