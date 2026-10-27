<?php

namespace Tests\Unit\Resources\Product;

use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\ProductPriceDomainObject;
use HiEvents\Resources\Product\ProductResourcePublic;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Tests\TestCase;

class ProductResourcePublicTest extends TestCase
{
    private function makeProduct(string $productType, bool $isRefundProtection): ProductDomainObject
    {
        $price = (new ProductPriceDomainObject())
            ->setId(10)
            ->setPrice(0.49);

        /** @var ProductDomainObject $product */
        $product = ProductDomainObject::hydrateFromArray([
            'id' => 13,
            'event_id' => 7,
            'title' => 'Option sérénité',
            'order' => 1,
            'created_at' => '2026-01-01 00:00:00',
            'type' => 'PAID',
            'product_type' => $productType,
            'is_hidden' => false,
            'is_refund_protection' => $isRefundProtection,
        ]);

        return $product->setProductPrices(new Collection([$price]));
    }

    public function test_exposes_refund_protection_flag_so_options_are_not_taken_as_ticket_prices(): void
    {
        $resource = (new ProductResourcePublic($this->makeProduct('GENERAL', true)))
            ->toArray(Request::create('/'));

        $this->assertTrue($resource['is_refund_protection']);
        $this->assertSame('GENERAL', $resource['product_type']);
    }

    public function test_regular_ticket_is_not_flagged_as_refund_protection(): void
    {
        $resource = (new ProductResourcePublic($this->makeProduct('TICKET', false)))
            ->toArray(Request::create('/'));

        $this->assertFalse($resource['is_refund_protection']);
        $this->assertSame('TICKET', $resource['product_type']);
    }
}
