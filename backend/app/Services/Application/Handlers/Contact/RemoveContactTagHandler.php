<?php

namespace HiEvents\Services\Application\Handlers\Contact;

use HiEvents\DomainObjects\ContactTagAssignmentDomainObject;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Repository\Interfaces\ContactTagAssignmentRepositoryInterface;

class RemoveContactTagHandler
{
    public function __construct(
        private readonly ContactLoader                           $contactLoader,
        private readonly ContactTagAssignmentRepositoryInterface $contactTagAssignmentRepository,
    )
    {
    }

    /**
     * @throws ResourceNotFoundException
     */
    public function handle(int $organizerId, int $contactId, int $tagId): void
    {
        $this->contactLoader->load($organizerId, $contactId);

        $this->contactTagAssignmentRepository->deleteWhere([
            ContactTagAssignmentDomainObject::CONTACT_ID => $contactId,
            ContactTagAssignmentDomainObject::CONTACT_TAG_ID => $tagId,
        ]);
    }
}
