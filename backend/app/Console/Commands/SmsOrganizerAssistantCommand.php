<?php

namespace HiEvents\Console\Commands;

use HiEvents\DomainObjects\Generated\OrganizerSmsSettingDomainObjectAbstract;
use HiEvents\Repository\Interfaces\OrganizerSmsSettingRepositoryInterface;
use HiEvents\Services\Domain\Sms\SmsAssistantReplyGenerator;
use Illuminate\Console\Command;

class SmsOrganizerAssistantCommand extends Command
{
    protected $signature = 'sms:organizer-assistant
        {organizer : ID of the organizer}
        {--enable : Let Anicka answer the ORGANIZER contacts of the team}
        {--disable : Stop the automatic answers to the team}
        {--instructions= : Instructions for Anicka, distinct from the customer assistant ones}';

    protected $description = 'Turn the organizer assistant (Anicka) on or off and set its instructions';

    public function handle(OrganizerSmsSettingRepositoryInterface $settingsRepository): int
    {
        $organizerId = (int) $this->argument('organizer');
        $settings = $settingsRepository->findFirstWhere([OrganizerSmsSettingDomainObjectAbstract::ORGANIZER_ID => $organizerId]);
        if ($settings === null) {
            $this->error('This organizer has no SMS settings: connect or share an SMS line first.');

            return self::FAILURE;
        }

        if ($this->option('enable') && $this->option('disable')) {
            $this->error('Choose --enable or --disable, not both.');

            return self::FAILURE;
        }

        $attributes = [];
        if ($this->option('enable') || $this->option('disable')) {
            $attributes[OrganizerSmsSettingDomainObjectAbstract::ORGANIZER_ASSISTANT_ENABLED] = (bool) $this->option('enable');
        }

        if ($this->option('instructions') !== null) {
            $instructions = SmsAssistantReplyGenerator::cleanText(str_replace('\n', "\n", (string) $this->option('instructions')));
            $attributes[OrganizerSmsSettingDomainObjectAbstract::ORGANIZER_ASSISTANT_INSTRUCTIONS] = $instructions !== '' ? $instructions : null;
        }

        if ($attributes !== []) {
            $settings = $settingsRepository->updateFromArray($settings->getId(), $attributes);
        }

        $this->info(sprintf(
            'Organizer assistant of organizer %d: %s. Instructions: %s',
            $organizerId,
            $settings->getOrganizerAssistantEnabled() ? 'on' : 'off',
            $settings->getOrganizerAssistantInstructions() ?? 'none',
        ));

        return self::SUCCESS;
    }
}
