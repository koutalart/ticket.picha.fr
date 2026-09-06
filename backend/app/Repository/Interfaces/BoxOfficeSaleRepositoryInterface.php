<?php

declare(strict_types=1);

namespace HiEvents\Repository\Interfaces;

use HiEvents\DomainObjects\BoxOfficeSaleDomainObject;
use Illuminate\Support\Collection;

/**
 * @extends RepositoryInterface<BoxOfficeSaleDomainObject>
 */
interface BoxOfficeSaleRepositoryInterface extends RepositoryInterface
{
    /**
     * @param  array<int, array{product_id: int, product_price_id: int, unit_amount: float, attendee_id: int, order_id: int}>  $rows
     */
    public function createItems(int $saleId, array $rows): void;

    public function findItemsBySaleId(int $saleId): Collection;
}
