<?php

namespace HiEvents\Http\Actions\Organizers\Contacts;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Services\Application\Handlers\Contact\GetContactTimelineHandler;
use Illuminate\Http\JsonResponse;

class GetContactTimelineAction extends BaseAction
{
    public function __construct(private readonly GetContactTimelineHandler $handler)
    {
    }

    /**
     * @throws ResourceNotFoundException
     */
    public function __invoke(int $organizerId, int $contactId): JsonResponse
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class);

        return $this->jsonResponse(
            $this->handler->handle($organizerId, $contactId)->map->toArray()->all(),
            wrapInData: true,
        );
    }
}
