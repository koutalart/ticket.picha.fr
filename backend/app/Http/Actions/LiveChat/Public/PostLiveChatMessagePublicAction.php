<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\LiveChat\Public;

use HiEvents\Exceptions\LiveChatLimitReachedException;
use HiEvents\Exceptions\LiveChatUnavailableException;
use HiEvents\Resources\LiveChat\LiveChatTurnResource;
use HiEvents\Services\Domain\LiveChat\LiveChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Throwable;

class PostLiveChatMessagePublicAction extends BaseLiveChatPublicAction
{
    /**
     * @throws ValidationException
     * @throws Throwable
     */
    public function __invoke(Request $request): JsonResponse|Response
    {
        $data = $this->validate($request, [
            'message' => ['required', 'string', 'max:'.LiveChatService::MAX_MESSAGE_LENGTH],
        ]);

        if (trim(strip_tags($data['message'])) === '') {
            throw ValidationException::withMessages(['message' => __('Message is required')]);
        }

        try {
            $turn = $this->handler->post($this->sessionId($request), $data['message'], $this->ip($request));
        } catch (LiveChatUnavailableException) {
            return $this->notFoundResponse();
        } catch (LiveChatLimitReachedException $exception) {
            return $this->limitResponse($exception);
        }

        return $this->resourceResponse(LiveChatTurnResource::class, $turn);
    }
}
