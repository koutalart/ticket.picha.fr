<?php

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\ProductPriceDomainObject;
use HiEvents\Models\ProductPrice;
use HiEvents\Repository\Interfaces\ProductPriceRepositoryInterface;

/**
 * @extends BaseRepository<ProductPriceDomainObject>
 */
class ProductPriceRepository extends BaseRepository implements ProductPriceRepositoryInterface
{
    public function lockForUpdateById(int $id): ?ProductPriceDomainObject
    {
        $model = $this->model->where('id', $id)->lockForUpdate()->first();
        $this->resetModel();

        return $this->handleSingleResult($model);
    }

    protected function getModel(): string
    {
        return ProductPrice::class;
    }

    public function getDomainObject(): string
    {
        return ProductPriceDomainObject::class;
    }
}
