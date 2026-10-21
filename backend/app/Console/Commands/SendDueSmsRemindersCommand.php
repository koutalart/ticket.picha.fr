<?php

namespace HiEvents\Console\Commands;

use HiEvents\Services\Domain\Sms\Reminder\SmsReminderDispatchService;
use Illuminate\Console\Command;

class SendDueSmsRemindersCommand extends Command
{
    protected $signature = 'sms-reminders:send';

    protected $description = 'Send the scheduled SMS reminders that are due (a limited number of SMS per run)';

    public function handle(SmsReminderDispatchService $dispatchService): int
    {
        $result = $dispatchService->dispatchDue();

        $this->info(sprintf(
            'Reminders started: %d, SMS sent: %d, failed: %d, reminders completed: %d',
            $result->startedReminders,
            $result->sent,
            $result->failed,
            $result->completedReminders,
        ));

        return self::SUCCESS;
    }
}
