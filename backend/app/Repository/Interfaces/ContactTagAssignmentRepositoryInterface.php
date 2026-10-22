<?php

namespace HiEvents\Repository\Interfaces;

use HiEvents\DomainObjects\ContactTagAssignmentDomainObject;

/**
 * @extends RepositoryInterface<ContactTagAssignmentDomainObject>
 */
interface ContactTagAssignmentRepositoryInterface extends RepositoryInterface
{
    /**
     * @param int[] $contactIds
     */
    public function assignToContacts(int $tagId, array $contactIds): int;
}
