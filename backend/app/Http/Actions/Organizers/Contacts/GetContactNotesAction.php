<?php

namespace HiEvents\Http\Actions\Organizers\Contacts;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Resources\Contact\ContactNoteResource;
use HiEvents\Services\Application\Handlers\Contact\GetContactNotesHandler;
use Illuminate\Http\JsonResponse;

class GetContactNotesAction extends BaseAction
{
    public function __construct(private readonly GetContactNotesHandler $handler) {}

    /**
     * @throws ResourceNotFoundException
     */
    public function __invoke(int $organizerId, int $contactId): JsonResponse
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class);

        return $this->resourceResponse(
            resource: ContactNoteResource::class,
            data: $this->handler->handle($organizerId, $contactId),
        );
    }
}
