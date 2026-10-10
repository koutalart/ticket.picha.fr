<?php

namespace HiEvents\Services\Application\Handlers\Contact;

use HiEvents\DomainObjects\ContactDomainObject;
use HiEvents\DomainObjects\ContactTagDomainObject;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Repository\Interfaces\ContactRepositoryInterface;

class ContactLoader
{
    public function __construct(private readonly ContactRepositoryInterface $contactRepository)
    {
    }

    /**
     * @throws ResourceNotFoundException
     */
    public function load(int $organizerId, int $contactId): ContactDomainObject
    {
        /** @var ContactDomainObject|null $contact */
        $contact = $this->contactRepository
            ->loadRelation(ContactTagDomainObject::class)
            ->findFirstWhere([
                ContactDomainObject::ID => $contactId,
                ContactDomainObject::ORGANIZER_ID => $organizerId,
            ]);

        if ($contact === null) {
            throw new ResourceNotFoundException(__('Contact not found'));
        }

        return $contact;
    }
}
