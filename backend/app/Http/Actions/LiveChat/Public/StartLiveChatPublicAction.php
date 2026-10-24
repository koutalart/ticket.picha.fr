<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\LiveChat\Public;

use HiEvents\Exceptions\LiveChatLimitReachedException;
use HiEvents\Exceptions\LiveChatUnavailableException;
use HiEvents\Resources\LiveChat\LiveChatTurnResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response as ResponseCodes;

class StartLiveChatPublicAction extends BaseLiveChatPublicAction
{
    /**
     * @throws ValidationException
     */
    public function __invoke(Request $request, int $organizerId): JsonResponse|Response
    {
        $data = $this->validate($request, [
            'event_id' => ['nullable', 'integer', 'min:1'],
        ]);

        try {
            $turn = $this->handler->start(
                $organizerId,
                isset($data['event_id']) ? (int) $data['event_id'] : null,
                $this->ip($request),
            );
        } catch (LiveChatUnavailableException) {
            return $this->notFoundResponse();
        } catch (LiveChatLimitReachedException $exception) {
            return $this->limitResponse($exception);
        }

        return $this->resourceResponse(LiveChatTurnResource::class, $turn, ResponseCodes::HTTP_CREATED);
    }
}
