<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\LiveChat\Public;

use HiEvents\Exceptions\LiveChatInvalidContactException;
use HiEvents\Exceptions\LiveChatUnavailableException;
use HiEvents\Resources\LiveChat\LiveChatTurnResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Throwable;

class SubmitLiveChatContactPublicAction extends BaseLiveChatPublicAction
{
    /**
     * @throws ValidationException
     * @throws Throwable
     */
    public function __invoke(Request $request): JsonResponse|Response
    {
        $data = $this->validate($request, [
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+\s().-]+$/', 'required_without:email'],
            'email' => ['nullable', 'email', 'max:255', 'required_without:phone'],
        ]);

        try {
            $turn = $this->handler->submitContact($this->sessionId($request), $data['phone'] ?? null, $data['email'] ?? null);
        } catch (LiveChatUnavailableException) {
            return $this->notFoundResponse();
        } catch (LiveChatInvalidContactException $exception) {
            throw ValidationException::withMessages([$exception->field => $exception->getMessage()]);
        }

        return $this->resourceResponse(LiveChatTurnResource::class, $turn);
    }
}
