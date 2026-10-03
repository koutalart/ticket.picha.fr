<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\BoxOffice;

use HiEvents\DomainObjects\Generated\AttendeeDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\OrderDomainObjectAbstract;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Services\Application\Handlers\BoxOffice\DTO\BoxOfficeOrderDetailDTO;
use HiEvents\Services\Domain\BoxOffice\BoxOfficeAttendeeSummaryService;

class GetBoxOfficeOrderHandler
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly AttendeeRepositoryInterface $attendeeRepository,
        private readonly BoxOfficeAttendeeSummaryService $attendeeSummaryService,
    ) {}

    /**
     * @throws ResourceNotFoundException
     */
    public function handle(int $eventId, string $orderPublicId): BoxOfficeOrderDetailDTO
    {
        $row = $this->orderRepository->findForBoxOffice($eventId, $orderPublicId);
        $order = $row === null ? null : $this->orderRepository->findFirstWhere([
            OrderDomainObjectAbstract::EVENT_ID => $eventId,
            OrderDomainObjectAbstract::PUBLIC_ID => $orderPublicId,
        ]);

        if ($row === null || $order === null) {
            throw new ResourceNotFoundException(__('Order not found'));
        }

        $attendees = $this->attendeeRepository
            ->findWhere([
                AttendeeDomainObjectAbstract::ORDER_ID => $order->getId(),
                AttendeeDomainObjectAbstract::EVENT_ID => $eventId,
            ])
            ->sortBy(fn ($attendee) => $attendee->getId())
            ->values();

        return new BoxOfficeOrderDetailDTO(
            order: GetBoxOfficeOrdersHandler::toListItem($row),
            tickets: $this->attendeeSummaryService->summarize($eventId, $attendees)->all(),
        );
    }
}
