<?php

namespace HiEvents\Console;

use HiEvents\Console\Commands\PurgeLiveChatsCommand;
use HiEvents\Console\Commands\PurgeOrganizerCalendarConsultationsCommand;
use HiEvents\Console\Commands\PurgeQuestionAnswerFilesCommand;
use HiEvents\Console\Commands\SendDueSmsRemindersCommand;
use HiEvents\Console\Commands\SmsWatchdogCommand;
use HiEvents\Console\Commands\SyncSmsContactsCommand;
use HiEvents\Jobs\Social\PublishDueSocialPostsJob;
use HiEvents\Jobs\Message\SendScheduledMessagesJob;
use HiEvents\Jobs\Waitlist\ProcessExpiredWaitlistOffersJob;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected function schedule(Schedule $schedule): void
    {
        $schedule->command('kiosk:purge-print-jobs')->hourly()->withoutOverlapping();
        $schedule->job(new SendScheduledMessagesJob)->everyMinute()->withoutOverlapping();
        $schedule->job(new ProcessExpiredWaitlistOffersJob)->everyMinute()->withoutOverlapping();
        $schedule->job(new PublishDueSocialPostsJob)->everyMinute()->withoutOverlapping();
        $schedule->command(PurgeQuestionAnswerFilesCommand::class)->hourly()->withoutOverlapping();
        $schedule->command(SyncSmsContactsCommand::class)->everyFifteenMinutes()->withoutOverlapping();
        $schedule->command(SendDueSmsRemindersCommand::class)->everyFiveMinutes()->withoutOverlapping(30)->runInBackground();
        $schedule->command(SmsWatchdogCommand::class)->everyMinute()->withoutOverlapping(5);
        $schedule->command('contacts:sync')->dailyAt('03:30')->withoutOverlapping();
        $schedule->command(PurgeLiveChatsCommand::class)->dailyAt('03:45')->withoutOverlapping();
        $schedule->command(PurgeOrganizerCalendarConsultationsCommand::class)->dailyAt('03:50')->withoutOverlapping();
    }

    protected function commands(): void
    {
        $this->load(__DIR__ . '/Commands');

        include base_path('routes/console.php');
    }
}
