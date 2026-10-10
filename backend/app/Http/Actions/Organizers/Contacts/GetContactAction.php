<?php

namespace HiEvents\Http\Actions\Organizers\Contacts;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Resources\Contact\ContactResource;
use HiEvents\Services\Application\Handlers\Contact\ContactLoader;
use Illuminate\Http\JsonResponse;

class GetContactAction extends BaseAction
{
    public function __construct(private readonly ContactLoader $contactLoader)
    {
    }

    /**
     * @throws ResourceNotFoundException
     */
    public function __invoke(int $organizerId, int $contactId): JsonResponse
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class);

        return $this->resourceResponse(
            resource: ContactResource::class,
            data: $this->contactLoader->load($organizerId, $contactId),
        );
    }
}
