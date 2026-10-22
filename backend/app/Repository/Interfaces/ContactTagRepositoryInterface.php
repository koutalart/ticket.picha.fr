<?php

namespace HiEvents\Repository\Interfaces;

use HiEvents\DomainObjects\ContactTagDomainObject;
use Illuminate\Support\Collection;

/**
 * @extends RepositoryInterface<ContactTagDomainObject>
 */
interface ContactTagRepositoryInterface extends RepositoryInterface
{
    /**
     * @return Collection<int, object{id: int, name: string, contacts_count: int}>
     */
    public function getWithCountsForOrganizer(int $organizerId): Collection;

    public function findOrCreateByName(int $organizerId, string $name): ContactTagDomainObject;
}
