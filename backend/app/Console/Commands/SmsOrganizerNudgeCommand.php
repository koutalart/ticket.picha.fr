<?php

namespace HiEvents\Console\Commands;

use HiEvents\Exceptions\OrganizerNudgeException;
use HiEvents\Services\Domain\Sms\OrganizerAssistant\DTO\OrganizerNudgePlanDTO;
use HiEvents\Services\Domain\Sms\OrganizerAssistant\OrganizerNudgeService;
use Illuminate\Console\Command;

class SmsOrganizerNudgeCommand extends Command
{
    protected $signature = 'sms:organizer-nudge
        {organizer : ID of the organizer}
        {--on : Nudge the ORGANIZER contacts who leave a message of Anicka unanswered for 72 hours}
        {--off : Stop the nudges}
        {--event= : Event whose time zone sets the sending hours (9:00 to 19:00)}
        {--dry-run : Show, for each ORGANIZER contact, what would leave and when, without sending}';

    protected $description = 'Turn on or off the nudges of the silent organizer, or show what would leave';

    public function handle(OrganizerNudgeService $service): int
    {
        $organizerId = (int) $this->argument('organizer');

        if ($this->option('on') && $this->option('off')) {
            $this->error('Choose --on or --off, not both.');

            return self::FAILURE;
        }

        try {
            $settings = $service->configure(
                organizerId: $organizerId,
                enabled: $this->option('on') ? true : ($this->option('off') ? false : null),
                eventId: $this->option('event') !== null ? (int) $this->option('event') : null,
            );
        } catch (OrganizerNudgeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Nudges of organizer %d: %s. Time zone: %s.',
            $organizerId,
            $settings->getOrganizerNudgeEnabled() ? 'on' : 'off',
            $service->timezone($organizerId, $settings),
        ));

        if (! $this->option('dry-run')) {
            return self::SUCCESS;
        }

        $plans = $service->plan($organizerId);
        if ($plans === []) {
            $this->comment('No ORGANIZER contact in the team.');
        }

        foreach ($plans as $plan) {
            $this->line(sprintf(
                '%s (%s): %s%s%s',
                $plan->contactName ?: 'no name',
                $plan->phone,
                $this->describe($plan),
                $plan->awaitedAt !== null ? sprintf(', unanswered message #%d of %s', $plan->awaitedMessageId, $plan->awaitedAt) : '',
                $plan->nextNudgeAt !== null && $plan->state !== OrganizerNudgePlanDTO::STATE_ANSWERED ? ', next nudge '.$plan->nextNudgeAt : '',
            ));

            if ($plan->text !== null && $plan->state !== OrganizerNudgePlanDTO::STATE_ANSWERED) {
                $this->line($plan->text);
                $this->newLine();
            }
        }

        $this->comment('Dry run: nothing sent.');

        return self::SUCCESS;
    }

    private function describe(OrganizerNudgePlanDTO $plan): string
    {
        return match ($plan->state) {
            OrganizerNudgePlanDTO::STATE_DUE => 'a nudge is due now',
            OrganizerNudgePlanDTO::STATE_OUTSIDE_HOURS => 'a nudge is due, waiting for 9:00',
            OrganizerNudgePlanDTO::STATE_WAITING => 'less than 72 hours since the message',
            OrganizerNudgePlanDTO::STATE_ANSWERED => 'answered, no nudge',
            OrganizerNudgePlanDTO::STATE_OPTED_OUT => 'replied STOP, never nudged',
            OrganizerNudgePlanDTO::STATE_SUPERVISOR => 'platform supervisor, never nudged',
            default => 'no message waiting for an answer',
        };
    }
}
