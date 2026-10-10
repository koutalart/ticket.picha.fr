<?php

namespace HiEvents\Services\Application\Handlers\Contact;

use HiEvents\DomainObjects\ContactNoteDomainObject;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Repository\Interfaces\ContactNoteRepositoryInterface;

class DeleteContactNoteHandler
{
    public function __construct(
        private readonly ContactLoader $contactLoader,
        private readonly ContactNoteRepositoryInterface $contactNoteRepository,
    ) {}

    /**
     * @throws ResourceNotFoundException
     */
    public function handle(int $organizerId, int $contactId, int $noteId): void
    {
        $this->contactLoader->load($organizerId, $contactId);

        $deleted = $this->contactNoteRepository->deleteWhere([
            ContactNoteDomainObject::ID => $noteId,
            ContactNoteDomainObject::CONTACT_ID => $contactId,
        ]);

        if ($deleted === 0) {
            throw new ResourceNotFoundException(__('Note not found'));
        }
    }
}
