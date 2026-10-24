<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\LiveChat\Public;

use HiEvents\Exceptions\LiveChatLimitReachedException;
use HiEvents\Exceptions\LiveChatUnavailableException;
use HiEvents\Resources\LiveChat\LiveChatRefundStepResource;
use HiEvents\Services\Domain\LiveChat\Refund\LiveChatRefundEligibilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Throwable;

class RequestLiveChatRefundCodePublicAction extends BaseLiveChatRefundPublicAction
{
    /**
     * @throws ValidationException
     * @throws Throwable
     */
    public function __invoke(Request $request): JsonResponse|Response
    {
        $data = $this->validate($request, [
            'order_reference' => ['required', 'string', 'max:'.LiveChatRefundEligibilityService::MAX_REFERENCE_LENGTH],
            'email' => ['required', 'email', 'max:255'],
        ]);

        try {
            $step = $this->refundHandler->requestCode($this->sessionId($request), $data['order_reference'], $data['email'], $this->ip($request));
        } catch (LiveChatUnavailableException) {
            return $this->notFoundResponse();
        } catch (LiveChatLimitReachedException $exception) {
            return $this->limitResponse($exception);
        }

        return $this->resourceResponse(LiveChatRefundStepResource::class, $step);
    }
}
