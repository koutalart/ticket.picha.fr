<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\LiveChat\Public;

use HiEvents\Exceptions\LiveChatUnavailableException;
use HiEvents\Resources\LiveChat\LiveChatTurnResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class GetLiveChatMessagesPublicAction extends BaseLiveChatPublicAction
{
    public function __invoke(Request $request): JsonResponse|Response
    {
        try {
            $turn = $this->handler->messages($this->sessionId($request));
        } catch (LiveChatUnavailableException) {
            return $this->notFoundResponse();
        }

        return $this->resourceResponse(LiveChatTurnResource::class, $turn);
    }
}
