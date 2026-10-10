<?php

namespace HiEvents\Http\Actions\Organizers\Contacts;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Services\Application\Handlers\Contact\DeleteContactNoteHandler;
use Illuminate\Http\Response;

class DeleteContactNoteAction extends BaseAction
{
    public function __construct(private readonly DeleteContactNoteHandler $handler) {}

    /**
     * @throws ResourceNotFoundException
     */
    public function __invoke(int $organizerId, int $contactId, int $noteId): Response
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class);

        $this->handler->handle($organizerId, $contactId, $noteId);

        return $this->deletedResponse();
    }
}
