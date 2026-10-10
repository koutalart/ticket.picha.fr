<?php

namespace HiEvents\Http\Actions\Organizers\Contacts;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Services\Application\Handlers\Contact\RemoveContactTagHandler;
use Illuminate\Http\Response;

class RemoveContactTagAction extends BaseAction
{
    public function __construct(private readonly RemoveContactTagHandler $handler)
    {
    }

    /**
     * @throws ResourceNotFoundException
     */
    public function __invoke(int $organizerId, int $contactId, int $tagId): Response
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class);

        $this->handler->handle($organizerId, $contactId, $tagId);

        return $this->deletedResponse();
    }
}
