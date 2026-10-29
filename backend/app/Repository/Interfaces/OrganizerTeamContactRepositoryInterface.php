<?php

declare(strict_types=1);

namespace HiEvents\Repository\Interfaces;

use HiEvents\DomainObjects\OrganizerTeamContactDomainObject;
use Illuminate\Support\Collection;

/**
 * @extends RepositoryInterface<OrganizerTeamContactDomainObject>
 */
interface OrganizerTeamContactRepositoryInterface extends RepositoryInterface
{
    /**
     * Team numbers of Mayotte or La Réunion saved with +33.
     *
     * @return Collection<int, OrganizerTeamContactDomainObject>
     */
    public function findWithFrenchOverseasPhones(): Collection;
}
