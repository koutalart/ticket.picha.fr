<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\LiveChat;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Resources\LiveChat\LiveChatSessionResource;
use HiEvents\Services\Application\Handlers\LiveChat\GetOrganizerLiveChatsHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GetOrganizerLiveChatsAction extends BaseAction
{
    public function __construct(
        private readonly GetOrganizerLiveChatsHandler $handler,
    ) {}

    public function __invoke(Request $request, int $organizerId): JsonResponse
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class);

        return $this->resourceResponse(LiveChatSessionResource::class, $this->handler->list(
            $organizerId,
            max(1, (int) $request->query('page', 1)),
            min(100, max(1, (int) $request->query('per_page', 30))),
        ));
    }
}
