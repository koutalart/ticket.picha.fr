<?php

namespace Tests\Unit\DomainObjects;

use HiEvents\DomainObjects\ProductPriceDomainObject;
use Tests\TestCase;

class ProductPriceLowStockTest extends TestCase
{
    public function test_few_places_left_are_reported_with_their_share(): void
    {
        $this->assertSame(
            ['remaining' => 7, 'percent_remaining' => 7],
            $this->price(initial: 100, available: 7)->getLowStock(),
        );
    }

    public function test_small_share_of_a_large_stock_is_reported(): void
    {
        $this->assertSame(
            ['remaining' => 120, 'percent_remaining' => 24],
            $this->price(initial: 500, available: 120)->getLowStock(),
        );
    }

    public function test_plenty_left_is_not_reported(): void
    {
        $this->assertNull($this->price(initial: 500, available: 300)->getLowStock());
        $this->assertNull($this->price(initial: 40, available: 30)->getLowStock());
    }

    public function test_unlimited_or_sold_out_prices_are_not_reported(): void
    {
        $this->assertNull($this->price(initial: null, available: null)->getLowStock());
        $this->assertNull($this->price(initial: 100, available: 0, sold: 100)->getLowStock());
    }

    private function price(?int $initial, ?int $available, int $sold = 0): ProductPriceDomainObject
    {
        return (new ProductPriceDomainObject)
            ->setInitialQuantityAvailable($initial)
            ->setQuantityAvailable($available)
            ->setQuantitySold($sold);
    }
}
