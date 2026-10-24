<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\LiveChat\Approval;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\Generated\EventDomainObjectAbstract;
use HiEvents\DomainObjects\LiveChatRefundRequestDomainObject;
use HiEvents\DomainObjects\Status\LiveChatRefundRequestStatus;
use HiEvents\Exceptions\RefundApprovalException;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Services\Application\Handlers\LiveChat\Approval\DTO\RefundApprovalViewDTO;
use HiEvents\Services\Domain\LiveChat\Refund\Approval\RefundApprovalLinkService;
use HiEvents\Services\Domain\LiveChat\Refund\Approval\RefundApprovalService;

class GetRefundApprovalHandler
{
    public function __construct(
        private readonly RefundApprovalService $approvalService,
        private readonly RefundApprovalLinkService $linkService,
        private readonly OrganizerRepositoryInterface $organizerRepository,
        private readonly EventRepositoryInterface $eventRepository,
    ) {}

    /**
     * @throws RefundApprovalException
     */
    public function forLink(int $requestId, ?string $token): RefundApprovalViewDTO
    {
        $request = $this->approvalService->find($requestId);
        if ($request === null || ! $this->linkService->isValid($request, $token)) {
            throw new RefundApprovalException(__('This link is not valid or has expired'));
        }

        return $this->view($request, $request->getApprovalLinkUsedAt() === null);
    }

    /**
     * @throws RefundApprovalException
     */
    public function forOrganizer(int $organizerId, int $requestId): RefundApprovalViewDTO
    {
        $request = $this->approvalService->find($requestId);
        if ($request === null || $request->getOrganizerId() !== $organizerId) {
            throw new RefundApprovalException(__('This refund request does not exist'));
        }

        return $this->view($request, true);
    }

    private function view(LiveChatRefundRequestDomainObject $request, bool $channelOpen): RefundApprovalViewDTO
    {
        $organizer = $this->organizerRepository->findById($request->getOrganizerId());
        /** @var EventDomainObject|null $event */
        $event = $request->getEventId() !== null
            ? $this->eventRepository->findFirstWhere([EventDomainObjectAbstract::ID => $request->getEventId()])
            : null;
        $order = $this->approvalService->order($request);

        return new RefundApprovalViewDTO(
            id: $request->getId(),
            reference: RefundApprovalService::reference($request),
            status: $request->getStatus(),
            decision: $request->getDecision(),
            organizerId: $organizer->getId(),
            organizerName: $organizer->getName(),
            eventTitle: $event?->getTitle(),
            customerName: $this->approvalService->customerName($order),
            orderReference: $order?->getPublicId(),
            amount: (float) $request->getAmount(),
            currency: $request->getCurrency() ?: 'EUR',
            amountText: $this->approvalService->amountText($request),
            requestedAt: $request->getAwaitingApprovalAt(),
            decidedAt: $request->getDecidedAt(),
            decidedByChannel: $request->getDecidedByChannel(),
            decidedByName: $request->getDecidedByName(),
            canDecide: $channelOpen && $request->getStatus() === LiveChatRefundRequestStatus::AWAITING_APPROVAL->name,
        );
    }
}
