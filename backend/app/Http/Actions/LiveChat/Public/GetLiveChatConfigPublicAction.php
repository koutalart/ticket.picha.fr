<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\LiveChat\Public;

use HiEvents\Resources\LiveChat\LiveChatConfigResource;
use Illuminate\Http\JsonResponse;

class GetLiveChatConfigPublicAction extends BaseLiveChatPublicAction
{
    public function __invoke(int $organizerId): JsonResponse
    {
        return $this->resourceResponse(LiveChatConfigResource::class, $this->handler->getConfig($organizerId));
    }
}
