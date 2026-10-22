<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Organizers\Newsletter;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Resources\Organizer\OrganizerNewsletterSubscriberResource;
use HiEvents\Services\Application\Handlers\Organizer\DTO\NewsletterSubscriberSearchDTO;
use HiEvents\Services\Application\Handlers\Organizer\GetOrganizerNewsletterSubscribersHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GetOrganizerNewsletterSubscribersAction extends BaseAction
{
    public function __construct(
        private readonly GetOrganizerNewsletterSubscribersHandler $handler,
    )
    {
    }

    public function __invoke(Request $request, int $organizerId): JsonResponse
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class);

        return $this->resourceResponse(
            OrganizerNewsletterSubscriberResource::class,
            $this->handler->handle($organizerId, new NewsletterSubscriberSearchDTO(
                query: mb_substr((string)$request->query('query', ''), 0, 100),
                page: max(1, (int)$request->query('page', 1)),
                perPage: min(100, max(1, (int)$request->query('per_page', 25))),
            )),
        );
    }
}
