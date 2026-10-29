<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\LiveChat\Public;

use HiEvents\Resources\LiveChat\LiveChatConfigResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class GetLiveChatConfigPublicAction extends BaseLiveChatPublicAction
{
    /**
     * @throws ValidationException
     */
    public function __invoke(Request $request, int $organizerId): JsonResponse
    {
        $data = $this->validate($request, [
            'event_id' => ['nullable', 'integer', 'min:1'],
        ]);

        return $this->resourceResponse(
            LiveChatConfigResource::class,
            $this->handler->getConfig($organizerId, isset($data['event_id']) ? (int) $data['event_id'] : null),
        );
    }
}
