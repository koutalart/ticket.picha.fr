<?php

namespace HiEvents\Console\Commands;

use HiEvents\Exceptions\SmsSharedLineException;
use HiEvents\Services\Domain\Sms\SmsLineService;
use Illuminate\Console\Command;

class ShareSmsLineCommand extends Command
{
    protected $signature = 'sms:share-line
        {organizer : ID of the organizer that will send and receive SMS with the line}
        {--line= : ID of the organizer owning the httpSMS line}
        {--detach : Stop using the shared line}';

    protected $description = 'Attach an organizer to the httpSMS line of another organizer, or detach it';

    public function __construct(
        private readonly SmsLineService $lineService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $organizerId = (int) $this->argument('organizer');

        try {
            if ($this->option('detach')) {
                $this->lineService->detach($organizerId);
                $this->info(sprintf('Organizer %d no longer uses a shared line (former member for %d days). To give it its own line in the same step, use sms:detach-line.', $organizerId, $this->lineService->transitionWindowDays()));

                return self::SUCCESS;
            }

            $lineOrganizerId = (int) $this->option('line');
            if ($lineOrganizerId <= 0) {
                $this->error('Pass the organizer owning the line with --line=ID.');

                return self::FAILURE;
            }

            $settings = $this->lineService->attach($organizerId, $lineOrganizerId);
        } catch (SmsSharedLineException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Organizer %d now uses the line of organizer %d (line settings %d). Incoming SMS go to the organizer who wrote last to the number within %d days.',
            $organizerId,
            $lineOrganizerId,
            $settings->getSharedLineSettingsId(),
            $this->lineService->attributionWindowDays(),
        ));

        return self::SUCCESS;
    }
}
