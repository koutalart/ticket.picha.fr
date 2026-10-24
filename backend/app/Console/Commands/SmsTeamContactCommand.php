<?php

namespace HiEvents\Console\Commands;

use HiEvents\DomainObjects\Enums\OrganizerTeamContactRole;
use HiEvents\DomainObjects\OrganizerTeamContactDomainObject;
use HiEvents\Exceptions\InvalidSmsPhoneNumberException;
use HiEvents\Exceptions\OrganizerTeamContactException;
use HiEvents\Services\Domain\Sms\Team\OrganizerTeamContactService;
use Illuminate\Console\Command;

class SmsTeamContactCommand extends Command
{
    protected $signature = 'sms:team-contact
        {organizer : ID of the organizer}
        {phone? : Number of the team contact (E.164 or national). Without it, the team is listed}
        {--name= : Name of the person}
        {--role=ORGANIZER : ORGANIZER (gets the answers of Anicka) or COPY (in copy, no automatic answer)}
        {--remove : Remove the number from the team}';

    protected $description = 'Add, update, remove or list the team contacts of an organizer';

    public function handle(OrganizerTeamContactService $service): int
    {
        $organizerId = (int) $this->argument('organizer');
        $phone = $this->argument('phone');

        try {
            if ($phone === null) {
                $this->list($service, $organizerId);

                return self::SUCCESS;
            }

            if ($this->option('remove')) {
                $service->removeByPhone($organizerId, $phone);
                $this->info(sprintf('Number removed from the team of organizer %d.', $organizerId));
                $this->list($service, $organizerId);

                return self::SUCCESS;
            }

            $role = OrganizerTeamContactRole::tryFrom(strtoupper((string) $this->option('role')));
            if ($role === null) {
                $this->error('The role must be ORGANIZER or COPY.');

                return self::FAILURE;
            }

            $contact = $service->save($organizerId, $phone, $this->option('name'), $role);
        } catch (InvalidSmsPhoneNumberException|OrganizerTeamContactException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('%s is in the team of organizer %d as %s.', $contact->getPhone(), $organizerId, $contact->getRole()));
        $this->list($service, $organizerId);

        return self::SUCCESS;
    }

    private function list(OrganizerTeamContactService $service, int $organizerId): void
    {
        $this->table(['ID', 'Phone', 'Name', 'Role'], $service->list($organizerId)
            ->map(fn (OrganizerTeamContactDomainObject $contact) => [
                $contact->getId(),
                $contact->getPhone(),
                $contact->getName() ?? '',
                $contact->getRole(),
            ])->all());
    }
}
