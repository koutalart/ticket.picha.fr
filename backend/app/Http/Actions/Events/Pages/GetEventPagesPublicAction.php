<?php

namespace HiEvents\Http\Actions\Events\Pages;

use HiEvents\DomainObjects\Enums\Role;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Resources\Event\EventPagesResource;
use HiEvents\Services\Application\Handlers\Event\DTO\GetPublicEventPagesDTO;
use HiEvents\Services\Application\Handlers\Event\GetPublicEventPagesHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class GetEventPagesPublicAction extends BaseAction
{
    public function __construct(
        private readonly GetPublicEventPagesHandler $handler,
    ) {}

    public function __invoke(int $eventId): JsonResponse|Response
    {
        $isAuthenticated = $this->isUserAuthenticated();

        try {
            $pages = $this->handler->handle(new GetPublicEventPagesDTO(
                eventId: $eventId,
                authenticatedAccountId: $isAuthenticated ? $this->getAuthenticatedAccountId() : null,
                isSuperAdmin: $isAuthenticated && $this->getAuthenticatedUserRole() === Role::SUPERADMIN,
            ));
        } catch (ResourceNotFoundException) {
            return $this->notFoundResponse();
        }

        return $this->resourceResponse(EventPagesResource::class, $pages);
    }
}
