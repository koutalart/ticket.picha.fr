<?php

namespace HiEvents\Services\Application\Handlers\Contact;

use HiEvents\DomainObjects\ContactDomainObject;
use HiEvents\DomainObjects\ContactTagDomainObject;
use HiEvents\Repository\Interfaces\ContactRepositoryInterface;
use HiEvents\Repository\Interfaces\ContactTagAssignmentRepositoryInterface;
use HiEvents\Repository\Interfaces\ContactTagRepositoryInterface;

class AssignContactTagHandler
{
    public function __construct(
        private readonly ContactRepositoryInterface $contactRepository,
        private readonly ContactTagRepositoryInterface $contactTagRepository,
        private readonly ContactTagAssignmentRepositoryInterface $contactTagAssignmentRepository,
    ) {}

    /**
     * @param  int[]  $contactIds
     */
    public function handle(int $organizerId, string $tagName, array $contactIds): ContactTagDomainObject
    {
        $tag = $this->contactTagRepository->findOrCreateByName($organizerId, $tagName);

        $ownedContactIds = $this->contactRepository
            ->findWhereIn(ContactDomainObject::ID, $contactIds, [ContactDomainObject::ORGANIZER_ID => $organizerId], ['id'])
            ->map(fn (ContactDomainObject $contact) => $contact->getId())
            ->all();

        if ($ownedContactIds !== []) {
            $this->contactTagAssignmentRepository->assignToContacts($tag->getId(), $ownedContactIds);
        }

        return $tag;
    }
}
