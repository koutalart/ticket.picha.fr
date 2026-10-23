<?php

namespace HiEvents\Http\Actions\Events\Pages;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Resources\Event\EventPagesResource;
use HiEvents\Services\Application\Handlers\Event\GetEventPagesHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class GetEventPagesAction extends BaseAction
{
    public function __construct(
        private readonly GetEventPagesHandler $handler,
    ) {}

    public function __invoke(int $eventId): JsonResponse|Response
    {
        $this->isActionAuthorized($eventId, EventDomainObject::class);

        try {
            $pages = $this->handler->handle($eventId);
        } catch (ResourceNotFoundException) {
            return $this->notFoundResponse();
        }

        return $this->resourceResponse(EventPagesResource::class, $pages);
    }
}
