<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Organizers\Newsletter;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Services\Application\Handlers\Organizer\DeleteOrganizerNewsletterSubscriberHandler;
use Illuminate\Http\Response;

class DeleteOrganizerNewsletterSubscriberAction extends BaseAction
{
    public function __construct(
        private readonly DeleteOrganizerNewsletterSubscriberHandler $handler,
    )
    {
    }

    public function __invoke(int $organizerId, int $subscriberId): Response
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class);

        try {
            $this->handler->handle($organizerId, $subscriberId);
        } catch (ResourceNotFoundException) {
            return $this->notFoundResponse();
        }

        return $this->deletedResponse();
    }
}
