<?php

namespace HiEvents\Console\Commands;

use HiEvents\Exceptions\InvalidSmsPhoneNumberException;
use HiEvents\Exceptions\SmsSharedLineException;
use HiEvents\Services\Domain\Sms\SmsLineService;
use HiEvents\Services\Domain\Sms\SmsSettingsService;
use Illuminate\Console\Command;

class DetachSmsLineCommand extends Command
{
    protected $signature = 'sms:detach-line
        {organizer : ID of the organizer leaving the shared line}
        {--from-number= : Sending number of the new line of the organizer}
        {--api-key-env= : Name of the environment variable holding the PICHA SMS API key of the new line}';

    protected $description = 'Detach an organizer from a shared httpSMS line, optionally giving it its own line, and keep it as a former member during the transition window';

    public function __construct(
        private readonly SmsLineService $lineService,
        private readonly SmsSettingsService $settingsService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $organizerId = (int) $this->argument('organizer');
        $fromNumber = trim((string) $this->option('from-number'));
        $apiKey = null;

        if ($fromNumber !== '') {
            $apiKey = $this->readApiKey();
            if ($apiKey === null) {
                $this->error('The API key of the new line is missing: pass --api-key-env=NAME with the key in that variable, or type it when asked.');

                return self::FAILURE;
            }
        }

        try {
            $settings = $this->lineService->detach($organizerId, $apiKey, $fromNumber !== '' ? $fromNumber : null);
        } catch (SmsSharedLineException|InvalidSmsPhoneNumberException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Organizer %d left the shared line. For %d days, SMS received on that line from people it wrote to last still reach it, and its replies to them leave from that line.',
            $organizerId,
            $this->lineService->transitionWindowDays(),
        ));

        if ($settings->getFromNumber()) {
            $this->info(sprintf('New SMS of the organizer leave from %s.', $settings->getFromNumber()));
            $this->info(sprintf('Webhook to set on the new phone in PICHA SMS: %s', $this->settingsService->getWebhookUrl($settings)));
        } else {
            $this->warn('The organizer has no line of its own yet: add its API key and sending number in its SMS settings, otherwise its new SMS cannot leave.');
        }

        return self::SUCCESS;
    }

    private function readApiKey(): ?string
    {
        $variable = trim((string) $this->option('api-key-env'));
        $apiKey = $variable !== '' ? ($_SERVER[$variable] ?? $_ENV[$variable] ?? getenv($variable)) : false;

        if (($apiKey === false || trim($apiKey) === '') && $this->input->isInteractive()) {
            $apiKey = $this->secret('PICHA SMS API key of the new line');
        }

        $apiKey = trim((string) $apiKey);

        return $apiKey !== '' ? $apiKey : null;
    }
}
