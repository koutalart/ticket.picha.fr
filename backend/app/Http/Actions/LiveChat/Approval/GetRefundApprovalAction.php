<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\LiveChat\Approval;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Exceptions\RefundApprovalException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Resources\LiveChat\RefundApprovalResource;
use HiEvents\Services\Application\Handlers\LiveChat\Approval\GetRefundApprovalHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class GetRefundApprovalAction extends BaseAction
{
    public function __construct(
        private readonly GetRefundApprovalHandler $handler,
    ) {}

    public function __invoke(int $organizerId, int $requestId): JsonResponse|Response
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class);

        try {
            return $this->resourceResponse(RefundApprovalResource::class, $this->handler->forOrganizer($organizerId, $requestId));
        } catch (RefundApprovalException) {
            return $this->notFoundResponse();
        }
    }
}
