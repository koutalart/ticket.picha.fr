<?php

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\OrderItemDomainObject;
use HiEvents\Models\OrderItem;
use HiEvents\Repository\Interfaces\OrderItemRepositoryInterface;

/**
 * @extends BaseRepository<OrderItemDomainObject>
 */
class OrderItemRepository extends BaseRepository implements OrderItemRepositoryInterface
{
    protected function getModel(): string
    {
        return OrderItem::class;
    }

    public function getDomainObject(): string
    {
        return OrderItemDomainObject::class;
    }

    public function forceDeleteForOrderAndProducts(int $orderId, array $productIds): int
    {
        if ($productIds === []) {
            return 0;
        }

        return (int) OrderItem::withTrashed()
            ->where('order_id', $orderId)
            ->whereIn('product_id', $productIds)
            ->forceDelete();
    }
}
