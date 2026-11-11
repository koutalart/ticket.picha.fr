<?php

namespace HiEvents\Console\Commands;

use HiEvents\DomainObjects\Generated\OrganizerDomainObjectAbstract;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Helper\CustomDomainHelper;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use Illuminate\Console\Command;

class OrganizerShortLinkDomainCommand extends Command
{
    protected $signature = 'organizer:short-link-domain
        {organizer : Organizer ID}
        {domain? : Domain serving the short links (https://domain/code); omit to show the current one}
        {--clear : Go back to the organizer domain, or the platform}';

    protected $description = 'Set the domain of an organizer\'s short links (QR codes, SMS, e-mails)';

    public function handle(OrganizerRepositoryInterface $organizerRepository): int
    {
        /** @var OrganizerDomainObject|null $organizer */
        $organizer = $organizerRepository->findFirst((int) $this->argument('organizer'));
        if ($organizer === null) {
            $this->error(__('Organizer not found.'));

            return self::FAILURE;
        }

        $domain = $this->argument('domain');

        if ($domain === null && ! $this->option('clear')) {
            $this->info(sprintf('"%s" (#%d): %s', $organizer->getName(), $organizer->getId(), $organizer->getShortLinkDomain() ?? '-'));

            return self::SUCCESS;
        }

        $normalized = $this->option('clear') ? null : CustomDomainHelper::normalize((string) $domain);
        if (! $this->option('clear') && ($normalized === null || ! CustomDomainHelper::isValid($normalized))) {
            $this->error(__('This domain is not valid.'));

            return self::FAILURE;
        }

        $organizerRepository->updateFromArray($organizer->getId(), [
            OrganizerDomainObjectAbstract::SHORT_LINK_DOMAIN => $normalized,
        ]);

        $this->info(sprintf('"%s" (#%d): %s', $organizer->getName(), $organizer->getId(), $normalized ?? '-'));

        return self::SUCCESS;
    }
}
