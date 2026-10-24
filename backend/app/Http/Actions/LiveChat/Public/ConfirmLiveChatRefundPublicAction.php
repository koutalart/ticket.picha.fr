<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\LiveChat\Public;

use HiEvents\Exceptions\LiveChatRefundSelectionException;
use HiEvents\Exceptions\LiveChatUnavailableException;
use HiEvents\Resources\LiveChat\LiveChatRefundStepResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Only the ids of the tickets to cancel are read: the amount is always computed on the server.
 */
class ConfirmLiveChatRefundPublicAction extends BaseLiveChatRefundPublicAction
{
    /**
     * @throws ValidationException
     * @throws Throwable
     */
    public function __invoke(Request $request): JsonResponse|Response
    {
        $data = $this->validate($request, [
            'attendee_ids' => ['required', 'array', 'min:1', 'max:50'],
            'attendee_ids.*' => ['integer', 'min:1'],
        ]);

        try {
            $step = $this->refundHandler->confirm($this->sessionId($request), array_map('intval', $data['attendee_ids']));
        } catch (LiveChatUnavailableException) {
            return $this->notFoundResponse();
        } catch (LiveChatRefundSelectionException $exception) {
            throw ValidationException::withMessages(['attendee_ids' => $exception->getMessage()]);
        }

        return $this->resourceResponse(LiveChatRefundStepResource::class, $step);
    }
}
