<?php

namespace HiEvents\Http\Actions\Organizers\Contacts;

use HiEvents\DomainObjects\ContactDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Resources\Contact\ContactResource;
use HiEvents\Services\Application\Handlers\Contact\DTO\ContactFilterDTO;
use HiEvents\Services\Application\Handlers\Contact\GetContactsHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GetContactsAction extends BaseAction
{
    public function __construct(private readonly GetContactsHandler $handler) {}

    public function __invoke(Request $request, int $organizerId): JsonResponse
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class);

        $contacts = $this->handler->handle(
            organizerId: $organizerId,
            filter: ContactFilterDTO::fromQuery($request->query->all()),
            params: $this->getPaginationQueryParams($request),
        );

        return $this->filterableResourceResponse(
            resource: ContactResource::class,
            data: $contacts,
            domainObject: ContactDomainObject::class,
        );
    }
}
