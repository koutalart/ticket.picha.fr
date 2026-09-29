<?php

namespace HiEvents\Resources\BoxOffice;

use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\ProductPriceDomainObject;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ProductDomainObject
 */
class BoxOfficeProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getId(),
            'title' => $this->getTitle(),
            'product_type' => $this->getProductType(),
            'is_available' => $this->isAvailable(),
            'is_hidden' => $this->getIsHidden(),
            'is_scannable' => $this->isScannable(),
            'prices' => $this->getProductPrices()
                ?->map(fn (ProductPriceDomainObject $price) => [
                    'id' => $price->getId(),
                    'label' => $price->getLabel(),
                    'price' => $price->getPrice(),
                    'quantity_remaining' => $price->getQuantityRemaining(),
                ])
                ->values()
                ->all() ?? [],
        ];
    }
}
