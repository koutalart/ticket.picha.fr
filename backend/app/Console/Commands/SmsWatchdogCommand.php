<?php

namespace HiEvents\Console\Commands;

use HiEvents\Mail\Sms\SmsWatchdogAlertMail;
use HiEvents\Services\Domain\Sms\DTO\SmsWatchdogAnomalyDTO;
use HiEvents\Services\Domain\Sms\Watchdog\SmsWatchdogAlertService;
use HiEvents\Services\Domain\Sms\Watchdog\SmsWatchdogDetector;
use Illuminate\Console\Command;

class SmsWatchdogCommand extends Command
{
    protected $signature = 'sms:watchdog {--dry-run : Print the anomalies without recording or alerting}';

    protected $description = 'Look for SMS anomalies of every organizer with an SMS line and alert the platform owner once per anomaly';

    public function handle(SmsWatchdogDetector $detector, SmsWatchdogAlertService $alertService): int
    {
        $startedAt = microtime(true);
        $detection = $detector->detect();

        if ($this->option('dry-run')) {
            $this->table(
                ['Type', 'Organizer', 'Conversation', 'Phone', 'Time (Mayotte)', 'Key'],
                array_map(fn (SmsWatchdogAnomalyDTO $anomaly) => [
                    $anomaly->type->name,
                    $anomaly->organizerName.' (#'.$anomaly->organizerId.')',
                    $anomaly->conversationId ?? '',
                    SmsWatchdogAlertMail::maskPhone($anomaly->phone),
                    SmsWatchdogAlertMail::localTime($anomaly->occurredAt),
                    $anomaly->key,
                ], $detection->anomalies),
            );
            $this->info(sprintf(
                'Dry run: %d anomalies, nothing recorded, no alert sent (%d ms)',
                count($detection->anomalies),
                $this->elapsedMs($startedAt),
            ));

            return self::SUCCESS;
        }

        $result = $alertService->process($detection);

        $this->info(sprintf(
            'Anomalies: %d, alerted: %d, waiting for the next e-mail: %d, resolved: %d%s (%d ms)',
            $result->detected,
            $result->alerted,
            $result->deferred,
            $result->resolved,
            $result->recipientMissing ? ' (no alert e-mail configured)' : '',
            $this->elapsedMs($startedAt),
        ));

        return self::SUCCESS;
    }

    private function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
