<?php

namespace HiEvents\Services\Domain\Attendee;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\Enums\CapacityChangeDirection;
use HiEvents\DomainObjects\Status\AttendeeStatus;
use HiEvents\Events\CapacityChangedEvent;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Services\Domain\EventStatistics\EventStatisticsCancellationService;
use HiEvents\Services\Domain\Product\ProductQuantityUpdateService;
use HiEvents\Services\Infrastructure\DomainEvents\DomainEventDispatcherService;
use HiEvents\Services\Infrastructure\DomainEvents\Enums\DomainEventType;
use HiEvents\Services\Infrastructure\DomainEvents\Events\AttendeeEvent;
use Illuminate\Contracts\Events\Dispatcher;
use Psr\Log\LoggerInterface;
use Throwable;

class AttendeeCancellationService
{
    public function __construct(
        private readonly AttendeeRepositoryInterface        $attendeeRepository,
        private readonly OrderRepositoryInterface           $orderRepository,
        private readonly ProductQuantityUpdateService       $productQuantityService,
        private readonly EventStatisticsCancellationService $eventStatisticsCancellationService,
        private readonly DomainEventDispatcherService       $domainEventDispatcherService,
        private readonly Dispatcher                         $eventDispatcher,
        private readonly LoggerInterface                    $logger,
    )
    {
    }

    /**
     * Cancels the attendee, frees the place and notifies listeners (capacity, waitlist, webhooks).
     * Returns false when the attendee had already changed status, so nothing is released twice.
     *
     * @throws Throwable
     */
    public function cancel(AttendeeDomainObject $attendee, array $additionalAttributes = []): bool
    {
        $updated = $this->attendeeRepository->updateWhere(
            attributes: array_merge($additionalAttributes, [
                'status' => AttendeeStatus::CANCELLED->name,
            ]),
            where: [
                'id' => $attendee->getId(),
                'status' => $attendee->getStatus(),
            ],
        );

        if ($updated === 0) {
            return false;
        }

        $this->productQuantityService->decreaseQuantitySold($attendee->getProductPriceId());

        $this->decrementEventStatistics($attendee);

        $this->domainEventDispatcherService->dispatch(
            new AttendeeEvent(
                type: DomainEventType::ATTENDEE_CANCELLED,
                attendeeId: $attendee->getId(),
            )
        );

        $this->eventDispatcher->dispatch(new CapacityChangedEvent(
            eventId: $attendee->getEventId(),
            direction: CapacityChangeDirection::INCREASED,
            productId: $attendee->getProductId(),
            productPriceId: $attendee->getProductPriceId(),
        ));

        return true;
    }

    /**
     * @throws Throwable
     */
    private function decrementEventStatistics(AttendeeDomainObject $attendee): void
    {
        $order = $this->orderRepository->findFirstWhere([
            'id' => $attendee->getOrderId(),
            'event_id' => $attendee->getEventId(),
        ]);

        if ($order === null) {
            $this->logger->error('Order not found when adjusting event statistics for attendee', [
                'attendee_id' => $attendee->getId(),
                'order_id' => $attendee->getOrderId(),
                'event_id' => $attendee->getEventId(),
            ]);
            return;
        }

        $this->eventStatisticsCancellationService->decrementForCancelledAttendee(
            eventId: $attendee->getEventId(),
            orderDate: $order->getCreatedAt()
        );
    }
}
