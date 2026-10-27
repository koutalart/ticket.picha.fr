<?php

namespace HiEvents\Repository\Interfaces;

use HiEvents\DomainObjects\OrderItemDomainObject;

/**
 * @extends RepositoryInterface<OrderItemDomainObject>
 */
interface OrderItemRepositoryInterface extends RepositoryInterface
{
    /**
     * Hard delete: reserved quantities are computed from order_items without looking at deleted_at.
     *
     * @param int[] $productIds
     */
    public function forceDeleteForOrderAndProducts(int $orderId, array $productIds): int;
}
