<?php

namespace Tests\Unit\Resources\Product;

use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\ProductPriceDomainObject;
use HiEvents\Resources\Product\ProductResource;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Tests\TestCase;

class ProductResourceTest extends TestCase
{
    private function makeProduct(): ProductDomainObject
    {
        $price = (new ProductPriceDomainObject())
            ->setId(10)
            ->setPrice(0.00);

        /** @var ProductDomainObject $product */
        $product = ProductDomainObject::hydrateFromArray([
            'id' => 1,
            'event_id' => 2,
            'title' => 'Pass 1 jour',
            'order' => 1,
            'created_at' => '2026-01-01 00:00:00',
            'type' => 'PAID',
            'product_type' => 'TICKET',
            'is_hidden' => false,
        ]);

        return $product->setProductPrices(new Collection([$price]));
    }

    public function test_resource_exposes_is_available_and_not_status(): void
    {
        $resource = (new ProductResource($this->makeProduct()))->toArray(Request::create('/'));

        $this->assertArrayHasKey('is_available', $resource);
        $this->assertTrue($resource['is_available']);

        // The Box Office product filter relies on real fields only. There is no
        // product-level `status` on the resource, the domain object or the table.
        $this->assertArrayNotHasKey('status', $resource);
    }
}
