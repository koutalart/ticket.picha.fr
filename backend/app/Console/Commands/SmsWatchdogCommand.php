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

    protected $description = 'Look for SMS anomalies of the organizers whose assistant replies on its own and alert the platform owner once per anomaly';

    public function handle(SmsWatchdogDetector $detector, SmsWatchdogAlertService $alertService): int
    {
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
            $this->info(sprintf('Dry run: %d anomalies, nothing recorded, no alert sent', count($detection->anomalies)));

            return self::SUCCESS;
        }

        $result = $alertService->process($detection);

        $this->info(sprintf(
            'Anomalies: %d, alerted: %d, resolved: %d%s',
            $result->detected,
            $result->alerted,
            $result->resolved,
            $result->recipientMissing ? ' (no alert e-mail configured)' : '',
        ));

        return self::SUCCESS;
    }
}
