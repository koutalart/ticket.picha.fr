<?php

namespace HiEvents\Services\Application\Handlers\Contact;

use HiEvents\DomainObjects\ContactNoteDomainObject;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Repository\Interfaces\ContactNoteRepositoryInterface;
use HiEvents\Services\Infrastructure\HtmlPurifier\HtmlPurifierService;

class CreateContactNoteHandler
{
    public function __construct(
        private readonly ContactLoader                  $contactLoader,
        private readonly ContactNoteRepositoryInterface $contactNoteRepository,
        private readonly HtmlPurifierService            $purifier,
    )
    {
    }

    /**
     * @throws ResourceNotFoundException
     */
    public function handle(int $organizerId, int $contactId, int $userId, string $note): ContactNoteDomainObject
    {
        $this->contactLoader->load($organizerId, $contactId);

        return $this->contactNoteRepository->create([
            ContactNoteDomainObject::CONTACT_ID => $contactId,
            ContactNoteDomainObject::USER_ID => $userId,
            ContactNoteDomainObject::NOTE => strip_tags($this->purifier->purify(trim($note)) ?? ''),
        ]);
    }
}
