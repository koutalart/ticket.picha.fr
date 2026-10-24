<?php

namespace HiEvents\Console\Commands;

use HiEvents\Exceptions\SmsGatewayNotConfiguredException;
use HiEvents\Services\Domain\Sms\OrganizerAssistant\OrganizerIntroService;
use Illuminate\Console\Command;

class SmsOrganizerIntroCommand extends Command
{
    protected $signature = 'sms:organizer-intro
        {organizer : ID of the organizer}
        {--text= : Text to send instead of the proposed one}
        {--send : Send it to the ORGANIZER contacts of the team (without it, the text is only shown)}';

    protected $description = 'Show, then send once checked, the introduction SMS of Anicka to the people who run an organizer';

    public function handle(OrganizerIntroService $service): int
    {
        $organizerId = (int) $this->argument('organizer');
        $text = trim((string) $this->option('text'));
        $text = $text !== '' ? str_replace('\n', "\n", $text) : $service->defaultText($organizerId);
        $recipients = $service->recipients($organizerId);

        $this->line($text);
        $this->newLine();
        $this->info(sprintf('Recipients (ORGANIZER contacts): %s', $recipients === [] ? 'none' : implode(', ', $recipients)));

        if (! $this->option('send')) {
            $this->comment('Nothing sent. Add --send to send this text.');

            return self::SUCCESS;
        }

        if ($recipients === []) {
            $this->error('No ORGANIZER contact in the team: add one with sms:team-contact.');

            return self::FAILURE;
        }

        try {
            $result = $service->send($organizerId, $text);
        } catch (SmsGatewayNotConfiguredException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('Sent: %d, skipped after STOP: %d, failed: %d', count($result->sent), count($result->optedOut), count($result->failed)));

        return $result->failed === [] ? self::SUCCESS : self::FAILURE;
    }
}
