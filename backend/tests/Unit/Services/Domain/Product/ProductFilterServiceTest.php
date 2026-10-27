<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Domain\Product;

use HiEvents\DomainObjects\AccountDomainObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\ProductCategoryDomainObject;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\ProductPriceDomainObject;
use HiEvents\Repository\Interfaces\AccountRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\ProductRepositoryInterface;
use HiEvents\Services\Domain\Order\OrderPlatformFeePassThroughService;
use HiEvents\Services\Domain\Order\RefundProtection\RefundProtectionPolicy;
use HiEvents\Services\Domain\Product\AvailableProductQuantitiesFetchService;
use HiEvents\Services\Domain\Product\DTO\AvailableProductQuantitiesDTO;
use HiEvents\Services\Domain\Product\DTO\AvailableProductQuantitiesResponseDTO;
use HiEvents\Services\Domain\Product\ProductFilterService;
use HiEvents\Services\Domain\Product\ProductPriceService;
use HiEvents\Services\Domain\Product\ProductUnlockService;
use HiEvents\Services\Domain\Tax\TaxAndFeeCalculationService;
use Illuminate\Support\Collection;
use Mockery as m;
use Tests\TestCase;

class ProductFilterServiceTest extends TestCase
{
    private AvailableProductQuantitiesFetchService $quantitiesService;
    private AccountRepositoryInterface $accountRepository;
    private EventRepositoryInterface $eventRepository;
    private ProductFilterService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->quantitiesService = m::mock(AvailableProductQuantitiesFetchService::class);
        $this->accountRepository = m::mock(AccountRepositoryInterface::class);
        $this->eventRepository = m::mock(EventRepositoryInterface::class);

        $this->service = new ProductFilterService(
            m::mock(TaxAndFeeCalculationService::class),
            m::mock(ProductPriceService::class),
            $this->quantitiesService,
            m::mock(OrderPlatformFeePassThroughService::class),
            $this->accountRepository,
            $this->eventRepository,
            new ProductUnlockService(m::mock(ProductRepositoryInterface::class), $this->quantitiesService),
            new RefundProtectionPolicy(app('config')),
        );
    }

    public function test_filter_product_list_returns_an_empty_collection_untouched(): void
    {
        $this->quantitiesService->shouldNotReceive('getAvailableProductQuantities');
        $this->accountRepository->shouldNotReceive('loadRelation');

        $result = $this->service->filterProductList(new Collection());

        self::assertTrue($result->isEmpty());
    }

    public function test_filter_product_list_processes_a_flat_product_list_without_category_wrapping(): void
    {
        $this->stubEventLevelCollaborators(eventId: 42);

        $products = new Collection([
            $this->makeFreeProduct(id: 1, eventId: 42, categoryId: 7),
            $this->makeFreeProduct(id: 2, eventId: 42, categoryId: 7),
        ]);

        $result = $this->service->filterProductList($products, hideSoldOutProducts: false);

        self::assertCount(2, $result);
        $result->each(fn($product) => self::assertInstanceOf(ProductDomainObject::class, $product));
        self::assertEqualsCanonicalizing([1, 2], $result->map(fn($p) => $p->getId())->all());
    }

    public function test_filter_still_returns_categories_with_their_nested_products(): void
    {
        $this->stubEventLevelCollaborators(eventId: 42);

        $category = new ProductCategoryDomainObject();
        $category->setId(7)->setIsHidden(false);
        $category->setProducts(new Collection([
            $this->makeFreeProduct(id: 1, eventId: 42, categoryId: 7),
        ]));

        $result = $this->service->filter(
            productsCategories: new Collection([$category]),
            hideSoldOutProducts: false,
            hideHiddenCategories: false,
        );

        self::assertCount(1, $result);
        self::assertInstanceOf(ProductCategoryDomainObject::class, $result->first());
        self::assertCount(1, $result->first()->getProducts());
        self::assertSame(1, $result->first()->getProducts()->first()->getId());
    }

    public function test_locked_product_is_hidden_publicly_while_source_product_is_available(): void
    {
        $this->stubEventLevelCollaborators(eventId: 42, productQuantities: new Collection([
            $this->makeQuantityRow(productId: 1, priceId: 10, available: 3),
            $this->makeQuantityRow(productId: 2, priceId: 20, available: 50),
        ]));

        $result = $this->service->filterProductList(new Collection([
            $this->makeFreeProduct(id: 1, eventId: 42, categoryId: 7),
            $this->makeFreeProduct(id: 2, eventId: 42, categoryId: 7)->setUnlockAfterProductId(1),
        ]));

        self::assertSame([1], $result->map(fn(ProductDomainObject $p) => $p->getId())->values()->all());
    }

    public function test_locked_product_stays_listed_for_the_organizer_but_unavailable(): void
    {
        $this->stubEventLevelCollaborators(eventId: 42, productQuantities: new Collection([
            $this->makeQuantityRow(productId: 1, priceId: 10, available: 3),
            $this->makeQuantityRow(productId: 2, priceId: 20, available: 50),
        ]));

        $result = $this->service->filterProductList(new Collection([
            $this->makeFreeProduct(id: 1, eventId: 42, categoryId: 7),
            $this->makeFreeProduct(id: 2, eventId: 42, categoryId: 7)->setUnlockAfterProductId(1),
        ]), hideSoldOutProducts: false);

        /** @var ProductDomainObject $locked */
        $locked = $result->first(fn(ProductDomainObject $p) => $p->getId() === 2);
        self::assertNotNull($locked);
        self::assertSame(__('This ticket opens when another ticket is sold out'), $locked->getOffSaleReason());
        self::assertFalse($locked->getProductPrices()->first()->isAvailable());
    }

    public function test_locked_product_appears_once_source_product_is_sold_out(): void
    {
        $this->stubEventLevelCollaborators(eventId: 42, productQuantities: new Collection([
            $this->makeQuantityRow(productId: 1, priceId: 10, available: 0),
            $this->makeQuantityRow(productId: 2, priceId: 20, available: 50),
        ]));

        $result = $this->service->filterProductList(new Collection([
            $this->makeFreeProduct(id: 1, eventId: 42, categoryId: 7),
            $this->makeFreeProduct(id: 2, eventId: 42, categoryId: 7)->setUnlockAfterProductId(1),
        ]));

        /** @var ProductDomainObject $unlocked */
        $unlocked = $result->first(fn(ProductDomainObject $p) => $p->getId() === 2);
        self::assertNotNull($unlocked);
        self::assertTrue($unlocked->getProductPrices()->first()->isAvailable());
    }

    private function makeQuantityRow(int $productId, int $priceId, int $available): AvailableProductQuantitiesDTO
    {
        return AvailableProductQuantitiesDTO::fromArray([
            'product_id' => $productId,
            'price_id' => $priceId,
            'product_title' => 'Product ' . $productId,
            'price_label' => null,
            'quantity_available' => $available,
            'quantity_reserved' => 0,
            'initial_quantity_available' => 100,
            'capacities' => new Collection(),
        ]);
    }

    private function stubEventLevelCollaborators(int $eventId, ?Collection $productQuantities = null): void
    {
        $account = m::mock(AccountDomainObject::class);
        $account->shouldReceive('getConfiguration')->andReturnNull();

        $this->accountRepository->shouldReceive('loadRelation')->andReturnSelf();
        $this->accountRepository->shouldReceive('findByEventId')->with($eventId)->andReturn($account);

        $event = m::mock(EventDomainObject::class);
        $event->shouldReceive('getEventSettings')->andReturnNull();
        $event->shouldReceive('getCurrency')->andReturn('USD');

        $this->eventRepository->shouldReceive('loadRelation')->andReturnSelf();
        $this->eventRepository->shouldReceive('findById')->with($eventId)->andReturn($event);

        $this->quantitiesService
            ->shouldReceive('getAvailableProductQuantities')
            ->with($eventId)
            ->andReturn(new AvailableProductQuantitiesResponseDTO(productQuantities: $productQuantities ?? new Collection()));
    }

    private function makeFreeProduct(int $id, int $eventId, int $categoryId): ProductDomainObject
    {
        $price = new ProductPriceDomainObject();
        $price->setId($id * 10)->setPrice(0.00);

        $product = new ProductDomainObject();
        $product
            ->setId($id)
            ->setEventId($eventId)
            ->setProductCategoryId($categoryId)
            ->setType('FREE')
            ->setProductType('TICKET')
            ->setProductPrices(new Collection([$price]));

        return $product;
    }
}
