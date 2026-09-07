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

    /**
     * @return array{
     *     items: list<array<string, mixed>>,
     *     total: int,
     *     current_page: int,
     *     last_page: int,
     *     per_page: int
     * }
     */
    public function paginateCompletedForKiosk(int $eventId, ?int $agentUserId, int $page, int $perPage = 20, ?string $search = null): array;

    /**
     * @return array{
     *     sales_count: int,
     *     ticket_count: int,
     *     total_amount: float,
     *     total_collected: float,
     *     by_payment_method: list<array<string, mixed>>,
     *     by_agent: list<array<string, mixed>>
     * }
     */
    public function aggregateCompletedForKiosk(int $eventId, ?int $agentUserId): array;
}
