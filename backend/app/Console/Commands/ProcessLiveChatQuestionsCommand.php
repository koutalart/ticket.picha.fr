<?php

namespace HiEvents\Console\Commands;

use HiEvents\Services\Domain\LiveChat\Question\LiveChatQuestionService;
use Illuminate\Console\Command;

class ProcessLiveChatQuestionsCommand extends Command
{
    protected $signature = 'live-chat:questions';

    protected $description = 'Remind the organizer once, and alert the supervision, about the chat questions without answer after 2 hours';

    public function handle(LiveChatQuestionService $service): int
    {
        $reminders = $service->processDue();
        if ($reminders > 0) {
            $this->info(sprintf('Reminders sent: %d', $reminders));
        }

        return self::SUCCESS;
    }
}
