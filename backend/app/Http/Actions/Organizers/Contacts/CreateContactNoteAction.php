<?php

namespace HiEvents\Http\Actions\Organizers\Contacts;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\Contact\CreateContactNoteRequest;
use HiEvents\Http\ResponseCodes;
use HiEvents\Resources\Contact\ContactNoteResource;
use HiEvents\Services\Application\Handlers\Contact\CreateContactNoteHandler;
use Illuminate\Http\JsonResponse;

class CreateContactNoteAction extends BaseAction
{
    public function __construct(private readonly CreateContactNoteHandler $handler)
    {
    }

    /**
     * @throws ResourceNotFoundException
     */
    public function __invoke(CreateContactNoteRequest $request, int $organizerId, int $contactId): JsonResponse
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class);

        $note = $this->handler->handle(
            organizerId: $organizerId,
            contactId: $contactId,
            userId: $this->getAuthenticatedUser()->getId(),
            note: $request->validated('note'),
        );

        return $this->resourceResponse(
            resource: ContactNoteResource::class,
            data: $note->setUser($this->getAuthenticatedUser()),
            statusCode: ResponseCodes::HTTP_CREATED,
        );
    }
}
