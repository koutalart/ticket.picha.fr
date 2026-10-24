<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\LiveChat\Approval;

use HiEvents\Exceptions\RefundApprovalException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\ResponseCodes;
use HiEvents\Resources\LiveChat\RefundApprovalResource;
use HiEvents\Services\Application\Handlers\LiveChat\Approval\GetRefundApprovalHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GetRefundApprovalPublicAction extends BaseAction
{
    public function __construct(
        private readonly GetRefundApprovalHandler $handler,
    ) {}

    public function __invoke(Request $request, int $requestId): JsonResponse
    {
        try {
            $view = $this->handler->forLink($requestId, $request->query('token'));
        } catch (RefundApprovalException $exception) {
            return $this->errorResponse($exception->getMessage(), ResponseCodes::HTTP_FORBIDDEN);
        }

        return $this->resourceResponse(RefundApprovalResource::class, $view);
    }
}
