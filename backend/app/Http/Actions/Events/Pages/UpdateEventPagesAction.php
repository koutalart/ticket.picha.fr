<?php

namespace HiEvents\Http\Actions\Events\Pages;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\Event\UpdateEventPagesRequest;
use HiEvents\Resources\Event\EventPagesResource;
use HiEvents\Services\Application\Handlers\Event\DTO\UpdateEventPagesDTO;
use HiEvents\Services\Application\Handlers\Event\UpdateEventPagesHandler;
use HiEvents\Services\Domain\Event\DTO\EventPagesDTO;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class UpdateEventPagesAction extends BaseAction
{
    public function __construct(
        private readonly UpdateEventPagesHandler $handler,
    ) {}

    public function __invoke(UpdateEventPagesRequest $request, int $eventId): JsonResponse|Response
    {
        $this->isActionAuthorized($eventId, EventDomainObject::class);

        $validated = $request->validated();

        try {
            $pages = $this->handler->handle(new UpdateEventPagesDTO(
                eventId: $eventId,
                pages: EventPagesDTO::from([
                    'artists' => $validated['artists'] ?? [],
                    'practical_info' => $validated['practical_info'] ?? [],
                    'faq' => $validated['faq'] ?? [],
                ]),
            ));
        } catch (ResourceNotFoundException) {
            return $this->notFoundResponse();
        }

        return $this->resourceResponse(EventPagesResource::class, $pages);
    }
}
