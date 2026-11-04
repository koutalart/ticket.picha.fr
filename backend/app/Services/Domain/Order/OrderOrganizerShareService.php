<?php

namespace HiEvents\Services\Domain\Order;

use HiEvents\DomainObjects\Generated\ProductDomainObjectAbstract;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrderItemDomainObject;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\ProductRepositoryInterface;
use HiEvents\Services\Domain\Order\DTO\OrderOrganizerShareDTO;
use Illuminate\Support\Collection;

/**
 * PICHA: what the buyer pays splits in two.
 * - PICHA keeps the service fees (platform fee) and the cancellation protection (is_refund_protection).
 * - The organizer keeps the price of the tickets and products, taxes included.
 *
 * A refund only gives back the organizer part: never the fees, never the protection.
 */
class OrderOrganizerShareService
{
    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly OrderRepositoryInterface   $orderRepository,
    )
    {
    }

    public function getShare(OrderDomainObject $order): OrderOrganizerShareDTO
    {
        $items = $this->getOrderItems($order);

        $totalGross = $this->toCents($order->getTotalGross());
        $serviceFees = $this->toCents($order->getTotalFee());
        $refundProtection = $this->getRefundProtectionCents($items);
        $organizerSales = max(0, $totalGross - $serviceFees - $refundProtection);
        $refundableToBuyer = max(0, $organizerSales - $this->toCents($order->getTotalRefunded()));

        return new OrderOrganizerShareDTO(
            totalGross: $this->fromCents($totalGross),
            serviceFees: $this->fromCents($serviceFees),
            refundProtection: $this->fromCents($refundProtection),
            organizerSales: $this->fromCents($organizerSales),
            refundableToBuyer: $this->fromCents($refundableToBuyer),
        );
    }

    /**
     * Part of a refund that comes off the organizer sales, for an order already refunded of $alreadyRefunded.
     */
    public function getOrganizerPartOfRefund(OrderDomainObject $order, float $refundAmount, float $alreadyRefunded): float
    {
        $remaining = max(0, $this->toCents($this->getShare($order)->organizerSales) - $this->toCents($alreadyRefunded));

        return $this->fromCents(min(max(0, $this->toCents($refundAmount)), $remaining));
    }

    /**
     * @return Collection<int, OrderItemDomainObject>
     */
    private function getOrderItems(OrderDomainObject $order): Collection
    {
        if ($order->getOrderItems() !== null) {
            return collect($order->getOrderItems());
        }

        if ($order->getId() === null) {
            return collect();
        }

        return collect($this->orderRepository
            ->loadRelation(OrderItemDomainObject::class)
            ->findById($order->getId())
            ->getOrderItems() ?? []);
    }

    /**
     * @param Collection<int, OrderItemDomainObject> $items
     */
    private function getRefundProtectionCents(Collection $items): int
    {
        $productIds = $items
            ->map(static fn(OrderItemDomainObject $item) => $item->getProductId())
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($productIds === []) {
            return 0;
        }

        $protectionIds = collect($this->productRepository->findWhereIn(ProductDomainObjectAbstract::ID, $productIds))
            ->filter(static fn(ProductDomainObject $product) => $product->getIsRefundProtection() === true)
            ->map(static fn(ProductDomainObject $product) => $product->getId())
            ->values()
            ->all();

        return (int)$items
            ->filter(static fn(OrderItemDomainObject $item) => in_array($item->getProductId(), $protectionIds, true))
            ->sum(fn(OrderItemDomainObject $item) => $this->toCents($item->getTotalGross()));
    }

    private function toCents(?float $amount): int
    {
        return (int)round(((float)$amount) * 100);
    }

    private function fromCents(int $cents): float
    {
        return $cents / 100;
    }
}
