<?php

declare(strict_types=1);

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\BoxOfficeSaleDomainObject;
use HiEvents\Models\BoxOfficeSale;
use HiEvents\Models\BoxOfficeSaleItem;
use HiEvents\Repository\Interfaces\BoxOfficeSaleRepositoryInterface;
use Illuminate\Support\Collection;

/**
 * @extends BaseRepository<BoxOfficeSaleDomainObject>
 */
class BoxOfficeSaleRepository extends BaseRepository implements BoxOfficeSaleRepositoryInterface
{
    protected function getModel(): string
    {
        return BoxOfficeSale::class;
    }

    public function getDomainObject(): string
    {
        return BoxOfficeSaleDomainObject::class;
    }

    public function createItems(int $saleId, array $rows): void
    {
        foreach ($rows as $row) {
            BoxOfficeSaleItem::query()->create([
                'box_office_sale_id' => $saleId,
                'product_id' => $row['product_id'],
                'product_price_id' => $row['product_price_id'],
                'unit_amount' => $row['unit_amount'],
                'attendee_id' => $row['attendee_id'],
                'order_id' => $row['order_id'],
            ]);
        }
    }

    public function findItemsBySaleId(int $saleId): Collection
    {
        return BoxOfficeSaleItem::query()
            ->where('box_office_sale_id', $saleId)
            ->orderBy('id')
            ->get();
    }
}
