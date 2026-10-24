<?php

namespace HiEvents\Console\Commands;

use HiEvents\Exceptions\EventDailyReportException;
use HiEvents\Exceptions\SmsGatewayNotConfiguredException;
use HiEvents\Services\Domain\Event\DailyReport\EventDailyReportService;
use Illuminate\Console\Command;

class EventDailyReportCommand extends Command
{
    protected $signature = 'events:daily-report
        {event : ID of the event}
        {--dry-run : Show the text of the report without sending it}
        {--enable : Turn the daily report on}
        {--disable : Turn the daily report off}
        {--time= : Local sending time, HH:MM}
        {--timezone= : Time zone of the sending time (Indian/Mayotte by default)}
        {--copy-supervision : Also send a copy to the platform supervisors}
        {--no-copy-supervision : Stop the copy to the platform supervisors}';

    protected $description = 'Configure the daily SMS report of an event, show it, or send it now to the organizer team';

    public function handle(EventDailyReportService $service): int
    {
        $eventId = (int) $this->argument('event');

        try {
            if ($this->changesSettings()) {
                return $this->applySettings($service, $eventId);
            }

            $report = $service->build($eventId);
            $this->line($report->text);

            if ($this->option('dry-run')) {
                $this->newLine();
                $this->comment('Dry run: nothing sent.');

                return self::SUCCESS;
            }

            $result = $service->sendNow($eventId);
        } catch (EventDailyReportException|SmsGatewayNotConfiguredException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Team: sent %d, skipped after STOP %d, failed %d. Supervision copies: %d',
            count($result->team->sent),
            count($result->team->optedOut),
            count($result->team->failed),
            count($result->supervision?->sent ?? []),
        ));

        return self::SUCCESS;
    }

    private function changesSettings(): bool
    {
        return $this->option('enable') || $this->option('disable') || $this->option('time') !== null
            || $this->option('timezone') !== null || $this->option('copy-supervision') || $this->option('no-copy-supervision');
    }

    /**
     * @throws EventDailyReportException
     */
    private function applySettings(EventDailyReportService $service, int $eventId): int
    {
        if (($this->option('enable') && $this->option('disable')) || ($this->option('copy-supervision') && $this->option('no-copy-supervision'))) {
            $this->error('Contradictory options.');

            return self::FAILURE;
        }

        $service->build($eventId);

        $report = $service->configure(
            eventId: $eventId,
            enabled: $this->option('enable') ? true : ($this->option('disable') ? false : null),
            sendTime: $this->option('time'),
            timezone: $this->option('timezone'),
            copySupervisors: $this->option('copy-supervision') ? true : ($this->option('no-copy-supervision') ? false : null),
        );

        $this->info(sprintf(
            'Daily report of event %d: %s at %s (%s), supervision copy %s, last sent on %s.',
            $eventId,
            $report->getEnabled() ? 'on' : 'off',
            $report->getSendTime(),
            $report->getTimezone(),
            $report->getCopySupervisors() ? 'on' : 'off',
            $report->getLastSentOn() ?? 'never',
        ));

        return self::SUCCESS;
    }
}
