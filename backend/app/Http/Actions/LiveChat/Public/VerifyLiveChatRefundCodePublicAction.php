<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\LiveChat\Public;

use HiEvents\Exceptions\LiveChatLimitReachedException;
use HiEvents\Exceptions\LiveChatUnavailableException;
use HiEvents\Resources\LiveChat\LiveChatRefundStepResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Throwable;

class VerifyLiveChatRefundCodePublicAction extends BaseLiveChatRefundPublicAction
{
    /**
     * @throws ValidationException
     * @throws Throwable
     */
    public function __invoke(Request $request): JsonResponse|Response
    {
        $data = $this->validate($request, [
            'code' => ['required', 'string', 'max:20'],
        ]);

        try {
            $step = $this->refundHandler->verifyCode($this->sessionId($request), $data['code'], $this->ip($request));
        } catch (LiveChatUnavailableException) {
            return $this->notFoundResponse();
        } catch (LiveChatLimitReachedException $exception) {
            return $this->limitResponse($exception);
        }

        return $this->resourceResponse(LiveChatRefundStepResource::class, $step);
    }
}
