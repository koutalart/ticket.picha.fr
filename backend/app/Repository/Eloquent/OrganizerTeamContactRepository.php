<?php

declare(strict_types=1);

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\OrganizerTeamContactDomainObject;
use HiEvents\Models\OrganizerTeamContact;
use HiEvents\Repository\Interfaces\OrganizerTeamContactRepositoryInterface;
use Illuminate\Support\Collection;

/**
 * @extends BaseRepository<OrganizerTeamContactDomainObject>
 */
class OrganizerTeamContactRepository extends BaseRepository implements OrganizerTeamContactRepositoryInterface
{
    protected function getModel(): string
    {
        return OrganizerTeamContact::class;
    }

    public function getDomainObject(): string
    {
        return OrganizerTeamContactDomainObject::class;
    }

    public function findWithFrenchOverseasPhones(): Collection
    {
        return $this->handleResults(OrganizerTeamContact::query()
            ->whereRaw("phone ~ '^\\+33(639|692|693|262|263|269)[0-9]{6}$'")
            ->orderBy('id')
            ->get());
    }
}
