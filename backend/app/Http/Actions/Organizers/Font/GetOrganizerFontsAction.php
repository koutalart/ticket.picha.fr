<?php

namespace HiEvents\Http\Actions\Organizers\Font;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Resources\Organizer\OrganizerFontResource;
use HiEvents\Services\Application\Handlers\Organizer\Font\GetOrganizerFontsHandler;
use Illuminate\Http\JsonResponse;

class GetOrganizerFontsAction extends BaseAction
{
    public function __construct(
        private readonly GetOrganizerFontsHandler $handler,
    ) {}

    public function __invoke(int $organizerId): JsonResponse
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class);

        return $this->resourceResponse(OrganizerFontResource::class, $this->handler->handle($organizerId));
    }
}
