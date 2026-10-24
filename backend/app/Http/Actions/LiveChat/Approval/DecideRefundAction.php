<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\LiveChat\Approval;

use HiEvents\DomainObjects\Enums\RefundDecision;
use HiEvents\DomainObjects\Enums\RefundDecisionChannel;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Exceptions\RefundAlreadyDecidedException;
use HiEvents\Exceptions\RefundApprovalException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\LiveChat\DecideRefundRequest;
use HiEvents\Http\ResponseCodes;
use HiEvents\Resources\LiveChat\RefundApprovalResource;
use HiEvents\Services\Application\Handlers\LiveChat\Approval\DecideLiveChatRefundHandler;
use HiEvents\Services\Application\Handlers\LiveChat\Approval\GetRefundApprovalHandler;
use HiEvents\Services\Domain\LiveChat\Refund\Approval\DTO\RefundDecisionActorDTO;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class DecideRefundAction extends BaseAction
{
    public function __construct(
        private readonly DecideLiveChatRefundHandler $decideHandler,
        private readonly GetRefundApprovalHandler $getHandler,
    ) {}

    public function __invoke(DecideRefundRequest $request, int $organizerId, int $requestId): JsonResponse|Response
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class);

        try {
            $this->getHandler->forOrganizer($organizerId, $requestId);
            $user = $this->getAuthenticatedUser();
            $this->decideHandler->decide($requestId, RefundDecision::from($request->validated('decision')), new RefundDecisionActorDTO(
                channel: RefundDecisionChannel::DASHBOARD,
                userId: $user->getId(),
                name: trim($user->getFirstName().' '.$user->getLastName()),
            ));
        } catch (RefundAlreadyDecidedException $exception) {
            return $this->errorResponse($exception->getMessage(), ResponseCodes::HTTP_CONFLICT);
        } catch (RefundApprovalException) {
            return $this->notFoundResponse();
        }

        return $this->resourceResponse(RefundApprovalResource::class, $this->getHandler->forOrganizer($organizerId, $requestId));
    }
}
