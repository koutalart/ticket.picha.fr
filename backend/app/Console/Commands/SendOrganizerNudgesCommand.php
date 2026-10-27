<?php

namespace HiEvents\Console\Commands;

use HiEvents\Services\Domain\Sms\OrganizerAssistant\DTO\OrganizerNudgePlanDTO;
use HiEvents\Services\Domain\Sms\OrganizerAssistant\OrganizerNudgeService;
use Illuminate\Console\Command;

class SendOrganizerNudgesCommand extends Command
{
    protected $signature = 'sms:send-organizer-nudges
        {--dry-run : List the nudges that would leave now, without sending}';

    protected $description = 'Send the nudges due to the silent organizers that turned them on (9:00 to 19:00 local time)';

    public function handle(OrganizerNudgeService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $results = $service->sendDue(dryRun: $dryRun);

        foreach ($results as $plan) {
            $this->line(sprintf('Organizer %d, %s: %s', $plan->organizerId, $plan->phone, $dryRun ? 'would be nudged' : $plan->state));
        }

        $sent = count(array_filter($results, fn (OrganizerNudgePlanDTO $plan) => $plan->state === OrganizerNudgePlanDTO::STATE_SENT));
        if ($sent > 0) {
            $this->info(sprintf('Nudges sent: %d', $sent));
        }

        return self::SUCCESS;
    }
}
