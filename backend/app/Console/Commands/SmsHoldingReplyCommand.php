<?php

namespace HiEvents\Console\Commands;

use HiEvents\DomainObjects\Generated\OrganizerSmsSettingDomainObjectAbstract;
use HiEvents\Repository\Interfaces\OrganizerSmsSettingRepositoryInterface;
use Illuminate\Console\Command;

class SmsHoldingReplyCommand extends Command
{
    protected $signature = 'sms:holding-reply
        {organizer : ID of the organizer}
        {--on : Send a holding reply when the assistant cannot answer}
        {--off : Never send a holding reply, the conversation only waits in « À traiter »}';

    protected $description = 'Turn the automatic holding reply of the customer SMS assistant on or off';

    public function handle(OrganizerSmsSettingRepositoryInterface $settingsRepository): int
    {
        $organizerId = (int) $this->argument('organizer');
        $settings = $settingsRepository->findFirstWhere([OrganizerSmsSettingDomainObjectAbstract::ORGANIZER_ID => $organizerId]);
        if ($settings === null) {
            $this->error('This organizer has no SMS settings: connect or share an SMS line first.');

            return self::FAILURE;
        }

        if ($this->option('on') && $this->option('off')) {
            $this->error('Choose --on or --off, not both.');

            return self::FAILURE;
        }

        if ($this->option('on') || $this->option('off')) {
            $settings = $settingsRepository->updateFromArray($settings->getId(), [
                OrganizerSmsSettingDomainObjectAbstract::ASSISTANT_HOLDING_REPLY_ENABLED => (bool) $this->option('on'),
            ]);
        }

        $this->info(sprintf(
            'Holding reply of organizer %d: %s.',
            $organizerId,
            $settings->getAssistantHoldingReplyEnabled() ? 'on' : 'off',
        ));

        return self::SUCCESS;
    }
}
