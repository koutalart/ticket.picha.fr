<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\LiveChat;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Resources\LiveChat\LiveChatSessionResource;
use HiEvents\Services\Application\Handlers\LiveChat\GetOrganizerLiveChatsHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class GetOrganizerLiveChatAction extends BaseAction
{
    public function __construct(
        private readonly GetOrganizerLiveChatsHandler $handler,
    ) {}

    public function __invoke(int $organizerId, int $sessionId): JsonResponse|Response
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class);

        try {
            $session = $this->handler->get($organizerId, $sessionId);
        } catch (ResourceNotFoundException) {
            return $this->notFoundResponse();
        }

        return $this->resourceResponse(LiveChatSessionResource::class, $session);
    }
}
