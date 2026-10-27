<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Order\Public;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\DomainObjects\StripePaymentDomainObject;
use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Exceptions\UnauthorizedException;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Services\Infrastructure\Session\CheckoutSessionManagementService;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;

/**
 * Loads a reserved, unexpired order of the current checkout session, for the refund protection endpoints.
 */
class RefundProtectionOrderLoader
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly EventRepositoryInterface $eventRepository,
        private readonly CheckoutSessionManagementService $sessionService,
    ) {}

    /**
     * @throws ResourceConflictException
     * @throws UnauthorizedException
     */
    public function loadOrder(int $eventId, string $orderShortId): OrderDomainObject
    {
        $order = $this->orderRepository
            ->loadRelation(new Relationship(StripePaymentDomainObject::class, name: 'stripe_payment'))
            ->findByShortId($orderShortId);

        if ($order === null || $order->getEventId() !== $eventId) {
            throw new ResourceNotFoundException(__('Order not found'));
        }

        if ($order->getSessionId() === null || ! $this->sessionService->verifySession($order->getSessionId())) {
            throw new UnauthorizedException(__('Sorry, we could not verify your session. Please restart your order.'));
        }

        if ($order->getStatus() !== OrderStatus::RESERVED->name || $order->isReservedOrderExpired()) {
            throw new ResourceConflictException(__('Sorry, is expired or not in a valid state.'));
        }

        return $order;
    }

    public function loadEvent(int $eventId): EventDomainObject
    {
        return $this->eventRepository
            ->loadRelation(EventSettingDomainObject::class)
            ->findById($eventId);
    }
}
