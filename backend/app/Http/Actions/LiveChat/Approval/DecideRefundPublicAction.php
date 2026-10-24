<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\LiveChat\Approval;

use HiEvents\DomainObjects\Enums\RefundDecision;
use HiEvents\Exceptions\RefundAlreadyDecidedException;
use HiEvents\Exceptions\RefundApprovalException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\LiveChat\DecideRefundRequest;
use HiEvents\Http\ResponseCodes;
use HiEvents\Resources\LiveChat\RefundApprovalResource;
use HiEvents\Services\Application\Handlers\LiveChat\Approval\DecideLiveChatRefundHandler;
use HiEvents\Services\Application\Handlers\LiveChat\Approval\GetRefundApprovalHandler;
use Illuminate\Http\JsonResponse;

class DecideRefundPublicAction extends BaseAction
{
    public function __construct(
        private readonly DecideLiveChatRefundHandler $decideHandler,
        private readonly GetRefundApprovalHandler $getHandler,
    ) {}

    public function __invoke(DecideRefundRequest $request, int $requestId): JsonResponse
    {
        $token = $request->validated('token');

        try {
            $this->decideHandler->decideWithLink($requestId, $token, RefundDecision::from($request->validated('decision')));
        } catch (RefundAlreadyDecidedException $exception) {
            return $this->errorResponse($exception->getMessage(), ResponseCodes::HTTP_CONFLICT);
        } catch (RefundApprovalException $exception) {
            return $this->errorResponse($exception->getMessage(), ResponseCodes::HTTP_FORBIDDEN);
        }

        return $this->resourceResponse(RefundApprovalResource::class, $this->getHandler->forLink($requestId, $token));
    }
}
