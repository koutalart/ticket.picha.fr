<?php

namespace HiEvents\Http\Actions\Organizers\Font;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\Organizer\Font\UpdateOrganizerFontFamilyRequest;
use HiEvents\Resources\Organizer\OrganizerFontResource;
use HiEvents\Services\Application\Handlers\Organizer\Font\UpdateOrganizerFontFamilyHandler;
use Illuminate\Http\JsonResponse;

class UpdateOrganizerFontFamilyAction extends BaseAction
{
    public function __construct(
        private readonly UpdateOrganizerFontFamilyHandler $handler,
    ) {}

    public function __invoke(UpdateOrganizerFontFamilyRequest $request, int $organizerId): JsonResponse
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class);

        return $this->resourceResponse(
            OrganizerFontResource::class,
            $this->handler->handle($organizerId, $request->validated('family_name')),
        );
    }
}
