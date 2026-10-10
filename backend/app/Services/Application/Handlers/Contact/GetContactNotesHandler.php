<?php

namespace HiEvents\Services\Application\Handlers\Contact;

use HiEvents\DomainObjects\ContactNoteDomainObject;
use HiEvents\DomainObjects\UserDomainObject;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Repository\Eloquent\Value\OrderAndDirection;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Repository\Interfaces\ContactNoteRepositoryInterface;
use Illuminate\Support\Collection;

class GetContactNotesHandler
{
    public function __construct(
        private readonly ContactLoader $contactLoader,
        private readonly ContactNoteRepositoryInterface $contactNoteRepository,
    ) {}

    /**
     * @throws ResourceNotFoundException
     */
    public function handle(int $organizerId, int $contactId): Collection
    {
        $this->contactLoader->load($organizerId, $contactId);

        return $this->contactNoteRepository
            ->loadRelation(new Relationship(UserDomainObject::class, name: 'user'))
            ->findWhere(
                where: [ContactNoteDomainObject::CONTACT_ID => $contactId],
                orderAndDirections: [new OrderAndDirection(ContactNoteDomainObject::CREATED_AT, 'desc')],
            );
    }
}
