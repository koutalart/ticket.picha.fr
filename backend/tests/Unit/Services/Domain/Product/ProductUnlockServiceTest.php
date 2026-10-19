<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Domain\Product;

use HiEvents\Constants;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\Exceptions\InvalidProductUnlockRuleException;
use HiEvents\Repository\Interfaces\ProductRepositoryInterface;
use HiEvents\Services\Domain\Product\AvailableProductQuantitiesFetchService;
use HiEvents\Services\Domain\Product\DTO\AvailableProductQuantitiesDTO;
use HiEvents\Services\Domain\Product\DTO\AvailableProductQuantitiesResponseDTO;
use HiEvents\Services\Domain\Product\ProductUnlockService;
use Illuminate\Support\Collection;
use Mockery as m;
use Mockery\MockInterface;
use Tests\TestCase;

class ProductUnlockServiceTest extends TestCase
{
    private ProductRepositoryInterface|MockInterface $productRepository;

    private AvailableProductQuantitiesFetchService|MockInterface $quantitiesService;

    private ProductUnlockService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->productRepository = m::mock(ProductRepositoryInterface::class);
        $this->quantitiesService = m::mock(AvailableProductQuantitiesFetchService::class);
        $this->service = new ProductUnlockService($this->productRepository, $this->quantitiesService);
    }

    public function test_product_is_locked_while_source_product_has_places_left(): void
    {
        $locked = $this->service->getLockedProductIds(
            $this->products(),
            $this->quantities([[1, 10, 1], [2, 20, 50]]),
        );

        self::assertSame([2], $locked);
    }

    public function test_product_is_unlocked_once_every_source_price_is_sold_out(): void
    {
        $locked = $this->service->getLockedProductIds(
            $this->products(),
            $this->quantities([[1, 10, 0], [1, 11, 0], [2, 20, 50]]),
        );

        self::assertSame([], $locked);
    }

    public function test_product_stays_locked_while_one_source_price_still_has_places(): void
    {
        $locked = $this->service->getLockedProductIds(
            $this->products(),
            $this->quantities([[1, 10, 0], [1, 11, 4], [2, 20, 50]]),
        );

        self::assertSame([2], $locked);
    }

    public function test_unlimited_source_product_never_unlocks(): void
    {
        $locked = $this->service->getLockedProductIds(
            $this->products(),
            $this->quantities([[1, 10, Constants::INFINITE], [2, 20, 50]]),
        );

        self::assertSame([2], $locked);
    }

    public function test_product_is_unlocked_when_source_product_no_longer_exists(): void
    {
        $locked = $this->service->getLockedProductIds(
            $this->products(),
            $this->quantities([[2, 20, 50]]),
        );

        self::assertSame([], $locked);
    }

    public function test_event_lookup_skips_quantity_fetch_when_no_rule_exists(): void
    {
        $this->quantitiesService->shouldNotReceive('getAvailableProductQuantities');

        $products = new Collection([(new ProductDomainObject)->setId(1)->setEventId(5)]);

        self::assertSame([], $this->service->getLockedProductIdsForEvent(5, $products));
    }

    public function test_event_lookup_uses_live_quantities(): void
    {
        $this->quantitiesService
            ->shouldReceive('getAvailableProductQuantities')
            ->once()
            ->with(5, true)
            ->andReturn($this->quantities([[1, 10, 2], [2, 20, 50]]));

        self::assertSame([2], $this->service->getLockedProductIdsForEvent(5, $this->products()));
    }

    public function test_rule_without_source_is_valid(): void
    {
        $this->productRepository->shouldNotReceive('findFirstWhere');

        $this->service->validateUnlockRule(5, 2, null);

        $this->addToAssertionCount(1);
    }

    public function test_rule_cannot_point_to_the_product_itself(): void
    {
        $this->expectException(InvalidProductUnlockRuleException::class);

        $this->service->validateUnlockRule(5, 2, 2);
    }

    public function test_rule_must_point_to_a_product_of_the_same_event(): void
    {
        $this->productRepository
            ->shouldReceive('findFirstWhere')
            ->with(['id' => 99, 'event_id' => 5])
            ->andReturnNull();

        $this->expectException(InvalidProductUnlockRuleException::class);

        $this->service->validateUnlockRule(5, 2, 99);
    }

    public function test_rule_rejects_two_products_opening_each_other(): void
    {
        $this->productRepository
            ->shouldReceive('findFirstWhere')
            ->andReturn((new ProductDomainObject)->setId(1)->setEventId(5)->setUnlockAfterProductId(2));

        $this->expectException(InvalidProductUnlockRuleException::class);

        $this->service->validateUnlockRule(5, 2, 1);
    }

    public function test_rule_pointing_to_another_product_of_the_event_is_valid(): void
    {
        $this->productRepository
            ->shouldReceive('findFirstWhere')
            ->andReturn((new ProductDomainObject)->setId(1)->setEventId(5));

        $this->service->validateUnlockRule(5, null, 1);

        $this->addToAssertionCount(1);
    }

    private function products(): Collection
    {
        return new Collection([
            (new ProductDomainObject)->setId(1)->setEventId(5),
            (new ProductDomainObject)->setId(2)->setEventId(5)->setUnlockAfterProductId(1),
        ]);
    }

    /**
     * @param  array<array{0:int,1:int,2:int}>  $rows  product id, price id, quantity available
     */
    private function quantities(array $rows): AvailableProductQuantitiesResponseDTO
    {
        return new AvailableProductQuantitiesResponseDTO(
            productQuantities: collect($rows)->map(fn (array $row) => AvailableProductQuantitiesDTO::fromArray([
                'product_id' => $row[0],
                'price_id' => $row[1],
                'product_title' => 'Product '.$row[0],
                'price_label' => null,
                'quantity_available' => $row[2],
                'quantity_reserved' => 0,
                'initial_quantity_available' => 100,
                'capacities' => new Collection,
            ])),
            capacities: new Collection,
        );
    }
}
