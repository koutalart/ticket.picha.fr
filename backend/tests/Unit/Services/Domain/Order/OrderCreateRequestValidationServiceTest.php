<?php

namespace Tests\Unit\Services\Domain\Order;

use HiEvents\DomainObjects\CapacityAssignmentDomainObject;
use HiEvents\DomainObjects\Enums\ProductPriceType;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\ProductPriceDomainObject;
use HiEvents\DomainObjects\Status\EventStatus;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\PromoCodeRepositoryInterface;
use HiEvents\Repository\Interfaces\ProductRepositoryInterface;
use HiEvents\Services\Domain\Order\OrderCreateRequestValidationService;
use HiEvents\Services\Domain\Product\AvailableProductQuantitiesFetchService;
use HiEvents\Services\Domain\Product\DTO\AvailableProductQuantitiesDTO;
use HiEvents\Services\Domain\Product\DTO\AvailableProductQuantitiesResponseDTO;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class OrderCreateRequestValidationServiceTest extends TestCase
{
    private ProductRepositoryInterface|MockInterface $productRepository;
    private PromoCodeRepositoryInterface|MockInterface $promoCodeRepository;
    private EventRepositoryInterface|MockInterface $eventRepository;
    private AvailableProductQuantitiesFetchService|MockInterface $availabilityService;
    private OrderCreateRequestValidationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->productRepository = Mockery::mock(ProductRepositoryInterface::class);
        $this->promoCodeRepository = Mockery::mock(PromoCodeRepositoryInterface::class);
        $this->eventRepository = Mockery::mock(EventRepositoryInterface::class);
        $this->availabilityService = Mockery::mock(AvailableProductQuantitiesFetchService::class);

        $this->service = new OrderCreateRequestValidationService(
            $this->productRepository,
            $this->promoCodeRepository,
            $this->eventRepository,
            $this->availabilityService,
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testZeroQuantityTiersAreSkippedDuringValidation(): void
    {
        $eventId = 1;
        $productId = 10;
        $selectedPriceId = 101;
        $unselectedPriceId = 102;

        $this->setupMocks(
            eventId: $eventId,
            productId: $productId,
            priceIds: [$selectedPriceId, $unselectedPriceId],
            priceLabels: ['Selected Tier', 'Unselected Tier'],
            availabilities: [
                ['price_id' => $selectedPriceId, 'quantity_available' => 5, 'quantity_reserved' => 0],
                ['price_id' => $unselectedPriceId, 'quantity_available' => 0, 'quantity_reserved' => 0],
            ],
        );

        $data = [
            'products' => [
                [
                    'product_id' => $productId,
                    'quantities' => [
                        ['price_id' => $selectedPriceId, 'quantity' => 1],
                        ['price_id' => $unselectedPriceId, 'quantity' => 0],
                    ],
                ],
            ],
        ];

        $this->service->validateRequestData($eventId, $data);
        $this->assertTrue(true);
    }

    public function testZeroQuantityTierWithNegativeAvailabilityDoesNotThrow(): void
    {
        $eventId = 1;
        $productId = 10;
        $healthyPriceId = 101;
        $brokenPriceId = 102;

        $this->setupMocks(
            eventId: $eventId,
            productId: $productId,
            priceIds: [$healthyPriceId, $brokenPriceId],
            priceLabels: ['Healthy Tier', 'Broken Tier'],
            availabilities: [
                ['price_id' => $healthyPriceId, 'quantity_available' => 10, 'quantity_reserved' => 0],
                ['price_id' => $brokenPriceId, 'quantity_available' => -5, 'quantity_reserved' => 0],
            ],
        );

        $data = [
            'products' => [
                [
                    'product_id' => $productId,
                    'quantities' => [
                        ['price_id' => $healthyPriceId, 'quantity' => 1],
                        ['price_id' => $brokenPriceId, 'quantity' => 0],
                    ],
                ],
            ],
        ];

        $this->service->validateRequestData($eventId, $data);
        $this->assertTrue(true);
    }

    public function testNonZeroQuantityStillValidatesAgainstAvailability(): void
    {
        $eventId = 1;
        $productId = 10;
        $priceId = 101;

        $this->setupMocks(
            eventId: $eventId,
            productId: $productId,
            priceIds: [$priceId],
            priceLabels: ['Test Tier'],
            availabilities: [
                ['price_id' => $priceId, 'quantity_available' => 2, 'quantity_reserved' => 0],
            ],
        );

        $data = [
            'products' => [
                [
                    'product_id' => $productId,
                    'quantities' => [
                        ['price_id' => $priceId, 'quantity' => 5],
                    ],
                ],
            ],
        ];

        $this->expectException(ValidationException::class);
        $this->service->validateRequestData($eventId, $data);
    }

    public function testNegativeQuantityOnAPriceTierIsRejected(): void
    {
        $eventId = 1;
        $productId = 10;
        $cheapPriceId = 101;
        $expensivePriceId = 102;

        $this->setupMocks(
            eventId: $eventId,
            productId: $productId,
            priceIds: [$cheapPriceId, $expensivePriceId],
            priceLabels: ['Cheap', 'VIP'],
            availabilities: [
                ['price_id' => $cheapPriceId, 'quantity_available' => 100, 'quantity_reserved' => 0],
                ['price_id' => $expensivePriceId, 'quantity_available' => 100, 'quantity_reserved' => 0],
            ],
        );

        $data = [
            'products' => [
                [
                    'product_id' => $productId,
                    'quantities' => [
                        ['price_id' => $cheapPriceId, 'quantity' => 5],
                        ['price_id' => $expensivePriceId, 'quantity' => -1],
                    ],
                ],
            ],
        ];

        $this->expectException(ValidationException::class);
        $this->service->validateRequestData($eventId, $data);
    }

    public function testUnrelatedOverReservedCapacityDoesNotBlockSelectedProduct(): void
    {
        $eventId = 1;
        $selectedProductId = 10;
        $selectedPriceId = 101;
        $unrelatedProductId = 20;
        $unrelatedPriceId = 201;

        $unrelatedProduct = Mockery::mock(ProductDomainObject::class);
        $unrelatedProduct->shouldReceive('getId')->andReturn($unrelatedProductId);

        $unrelatedCapacity = (new CapacityAssignmentDomainObject())
            ->setCapacity(10)
            ->setUsedCapacity(20)
            ->setProducts(collect([$unrelatedProduct]));

        $this->setupMocks(
            eventId: $eventId,
            productId: $selectedProductId,
            priceIds: [$selectedPriceId],
            priceLabels: ['Selected Product'],
            availabilities: [
                ['price_id' => $selectedPriceId, 'quantity_available' => 5, 'quantity_reserved' => 0],
            ],
            capacities: collect([$unrelatedCapacity]),
            extraAvailabilities: [
                [
                    'product_id' => $unrelatedProductId,
                    'price_id' => $unrelatedPriceId,
                    'quantity_available' => -10,
                    'quantity_reserved' => 0,
                ],
            ],
        );

        $data = [
            'products' => [
                [
                    'product_id' => $selectedProductId,
                    'quantities' => [
                        ['price_id' => $selectedPriceId, 'quantity' => 1],
                    ],
                ],
            ],
        ];

        $this->service->validateRequestData($eventId, $data);
        $this->assertTrue(true);
    }

    public function testSingleProductPerOrderAllowsOneRegistration(): void
    {
        $this->setupMocks(
            eventId: 1,
            productId: 10,
            priceIds: [101],
            priceLabels: ['Candidat'],
            availabilities: [
                ['price_id' => 101, 'quantity_available' => 5, 'quantity_reserved' => 0],
            ],
            singleProductPerOrder: true,
        );

        $this->service->validateRequestData(1, [
            'products' => [
                ['product_id' => 10, 'quantities' => [['price_id' => 101, 'quantity' => 1]]],
            ],
        ]);
        $this->assertTrue(true);
    }

    public function testSingleProductPerOrderRejectsTwoOfTheSameProduct(): void
    {
        $this->setupMocks(
            eventId: 1,
            productId: 10,
            priceIds: [101],
            priceLabels: ['Candidat'],
            availabilities: [
                ['price_id' => 101, 'quantity_available' => 5, 'quantity_reserved' => 0],
            ],
            singleProductPerOrder: true,
        );

        $this->expectException(ValidationException::class);

        $this->service->validateRequestData(1, [
            'products' => [
                ['product_id' => 10, 'quantities' => [['price_id' => 101, 'quantity' => 2]]],
            ],
        ]);
    }

    public function testSingleProductPerOrderRejectsOneOfEachAcrossProducts(): void
    {
        $this->setupMocks(
            eventId: 1,
            productId: 10,
            priceIds: [101],
            priceLabels: ['Candidat'],
            availabilities: [
                ['price_id' => 101, 'quantity_available' => 5, 'quantity_reserved' => 0],
            ],
            extraAvailabilities: [
                ['product_id' => 20, 'price_id' => 201, 'quantity_available' => 5, 'quantity_reserved' => 0],
            ],
            singleProductPerOrder: true,
        );

        try {
            $this->service->validateRequestData(1, [
                'products' => [
                    ['product_id' => 10, 'quantities' => [['price_id' => 101, 'quantity' => 1]]],
                    ['product_id' => 20, 'quantities' => [['price_id' => 201, 'quantity' => 1]]],
                ],
            ]);
            $this->fail('Expected a ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('products', $e->errors());
            $this->assertSame(
                __('Only one registration per order is allowed for this event.'),
                $e->errors()['products'][0],
            );
        }
    }

    public function testMultipleProductsAllowedWhenSingleProductPerOrderIsOff(): void
    {
        $secondProductPrice = Mockery::mock(ProductPriceDomainObject::class);
        $secondProductPrice->shouldReceive('getId')->andReturn(201);
        $secondProductPrice->shouldReceive('getLabel')->andReturn(null);

        $secondProduct = Mockery::mock(ProductDomainObject::class);
        $secondProduct->shouldReceive('getId')->andReturn(20);
        $secondProduct->shouldReceive('getEventId')->andReturn(1);
        $secondProduct->shouldReceive('getTitle')->andReturn('Exposant');
        $secondProduct->shouldReceive('getMaxPerOrder')->andReturn(100);
        $secondProduct->shouldReceive('getMinPerOrder')->andReturn(1);
        $secondProduct->shouldReceive('isSoldOut')->andReturn(false);
        $secondProduct->shouldReceive('getIsSalesPaused')->andReturn(false);
        $secondProduct->shouldReceive('getIsHidden')->andReturn(false);
        $secondProduct->shouldReceive('getType')->andReturn(ProductPriceType::TIERED->name);
        $secondProduct->shouldReceive('getProductPrices')->andReturn(new Collection([$secondProductPrice]));

        $this->setupMocks(
            eventId: 1,
            productId: 10,
            priceIds: [101],
            priceLabels: ['Candidat'],
            availabilities: [
                ['price_id' => 101, 'quantity_available' => 5, 'quantity_reserved' => 0],
            ],
            extraAvailabilities: [
                ['product_id' => 20, 'price_id' => 201, 'quantity_available' => 5, 'quantity_reserved' => 0],
            ],
            extraProducts: new Collection([$secondProduct]),
        );

        $this->service->validateRequestData(1, [
            'products' => [
                ['product_id' => 10, 'quantities' => [['price_id' => 101, 'quantity' => 2]]],
                ['product_id' => 20, 'quantities' => [['price_id' => 201, 'quantity' => 1]]],
            ],
        ]);
        $this->assertTrue(true);
    }

    private function setupMocks(
        int   $eventId,
        int   $productId,
        array $priceIds,
        array $priceLabels,
        array $availabilities,
        ?Collection $capacities = null,
        array $extraAvailabilities = [],
        bool  $singleProductPerOrder = false,
        ?Collection $extraProducts = null,
    ): void
    {
        $event = Mockery::mock(EventDomainObject::class);
        $event->shouldReceive('getId')->andReturn($eventId);
        $event->shouldReceive('getStatus')->andReturn(EventStatus::LIVE->name);
        $event->shouldReceive('getCurrency')->andReturn('USD');
        $event->shouldReceive('getEventSettings')->andReturn(
            (new EventSettingDomainObject())->setSingleProductPerOrder($singleProductPerOrder)
        );

        $this->eventRepository->shouldReceive('loadRelation')->with(EventSettingDomainObject::class)->andReturnSelf();
        $this->eventRepository->shouldReceive('findById')->with($eventId)->andReturn($event);

        $productPrices = new Collection();
        foreach ($priceIds as $i => $priceId) {
            $price = Mockery::mock(ProductPriceDomainObject::class);
            $price->shouldReceive('getId')->andReturn($priceId);
            $price->shouldReceive('getLabel')->andReturn($priceLabels[$i] ?? null);
            $productPrices->push($price);
        }

        $product = Mockery::mock(ProductDomainObject::class);
        $product->shouldReceive('getId')->andReturn($productId);
        $product->shouldReceive('getEventId')->andReturn($eventId);
        $product->shouldReceive('getTitle')->andReturn('Test Product');
        $product->shouldReceive('getMaxPerOrder')->andReturn(100);
        $product->shouldReceive('getMinPerOrder')->andReturn(1);
        $product->shouldReceive('isSoldOut')->andReturn(false);
        $product->shouldReceive('getIsSalesPaused')->andReturn(false);
        $product->shouldReceive('getIsHidden')->andReturn(false);
        $product->shouldReceive('getType')->andReturn(ProductPriceType::TIERED->name);
        $product->shouldReceive('getProductPrices')->andReturn($productPrices);

        $this->productRepository->shouldReceive('loadRelation')->andReturnSelf();
        $this->productRepository->shouldReceive('findWhereIn')->andReturn(
            (new Collection([$product]))->merge($extraProducts ?? collect())
        );

        $quantityDTOs = collect();
        foreach ($availabilities as $avail) {
            $quantityDTOs->push($this->makeQuantityDTO($avail, $productId));
        }

        foreach ($extraAvailabilities as $avail) {
            $quantityDTOs->push($this->makeQuantityDTO($avail, $productId));
        }

        $this->availabilityService->shouldReceive('getAvailableProductQuantities')
            ->with($eventId, Mockery::any())
            ->andReturn(new AvailableProductQuantitiesResponseDTO(
                productQuantities: $quantityDTOs,
                capacities: $capacities ?? collect(),
            ));
    }

    private function makeQuantityDTO(array $availability, int $defaultProductId): AvailableProductQuantitiesDTO
    {
        return AvailableProductQuantitiesDTO::fromArray([
            'product_id' => $availability['product_id'] ?? $defaultProductId,
            'price_id' => $availability['price_id'],
            'product_title' => 'Test Product',
            'price_label' => null,
            'quantity_available' => $availability['quantity_available'],
            'quantity_reserved' => $availability['quantity_reserved'],
            'initial_quantity_available' => 100,
            'capacities' => collect(),
        ]);
    }
}
