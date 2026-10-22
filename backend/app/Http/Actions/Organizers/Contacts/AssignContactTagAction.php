<?php

namespace HiEvents\Http\Actions\Organizers\Contacts;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\Contact\AssignContactTagRequest;
use HiEvents\Resources\Contact\ContactTagResource;
use HiEvents\Services\Application\Handlers\Contact\AssignContactTagHandler;
use Illuminate\Http\JsonResponse;

class AssignContactTagAction extends BaseAction
{
    public function __construct(private readonly AssignContactTagHandler $handler) {}

    public function __invoke(AssignContactTagRequest $request, int $organizerId): JsonResponse
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class);

        $tag = $this->handler->handle(
            organizerId: $organizerId,
            tagName: $request->validated('name'),
            contactIds: array_map('intval', $request->validated('contact_ids')),
        );

        return $this->resourceResponse(resource: ContactTagResource::class, data: $tag);
    }
}
