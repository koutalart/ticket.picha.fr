<?php

namespace Tests\Unit\Services\Domain\Order;

use Carbon\Carbon;
use HiEvents\DomainObjects\Enums\ProductPriceType;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\ProductPriceDomainObject;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\ProductRepositoryInterface;
use HiEvents\Repository\Interfaces\PromoCodeRepositoryInterface;
use HiEvents\Services\Application\Handlers\Attendee\CreateAttendeeHandler;
use HiEvents\Services\Application\Handlers\BoxOffice\CreateBoxOfficeSaleHandler;
use HiEvents\Services\Domain\Order\OrderCreateRequestValidationService;
use HiEvents\Services\Domain\Product\AvailableProductQuantitiesFetchService;
use HiEvents\Services\Domain\Product\DTO\AvailableProductQuantitiesDTO;
use HiEvents\Services\Domain\Product\DTO\AvailableProductQuantitiesResponseDTO;
use HiEvents\Services\Domain\Product\ProductUnlockService;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Mockery;
use Mockery\MockInterface;
use ReflectionClass;
use Tests\TestCase;

class RequiredProductOrderValidationTest extends TestCase
{
    private const EVENT_ID = 15;
    private const TICKET_ID = 30;
    private const TICKET_PRICE_ID = 300;
    private const REQUIRED_ID = 33;
    private const REQUIRED_PRICE_ID = 330;
    private const MERCH_ID = 40;
    private const MERCH_PRICE_ID = 400;

    private ProductRepositoryInterface|MockInterface $productRepository;
    private EventRepositoryInterface|MockInterface $eventRepository;
    private AvailableProductQuantitiesFetchService|MockInterface $availabilityService;
    private OrderCreateRequestValidationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->productRepository = Mockery::mock(ProductRepositoryInterface::class);
        $this->eventRepository = Mockery::mock(EventRepositoryInterface::class);
        $this->availabilityService = Mockery::mock(AvailableProductQuantitiesFetchService::class);

        $this->service = new OrderCreateRequestValidationService(
            $this->productRepository,
            Mockery::mock(PromoCodeRepositoryInterface::class),
            $this->eventRepository,
            $this->availabilityService,
            new ProductUnlockService($this->productRepository, $this->availabilityService),
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testOrderWithTicketButWithoutRequiredProductIsRejected(): void
    {
        $this->arrange(requiredProduct: $this->requiredProduct());

        try {
            $this->service->validateRequestData(self::EVENT_ID, $this->order(ticketQuantity: 2));
            $this->fail('Expected a ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(
                __(':product is included in every order (quantity: :quantity).', ['product' => 'Option sérénité', 'quantity' => 1]),
                $e->errors()['products'][0],
            );
        }
    }

    public function testOrderWithTicketAndRequiredProductIsAccepted(): void
    {
        $this->arrange(requiredProduct: $this->requiredProduct());

        $this->service->validateRequestData(self::EVENT_ID, $this->order(ticketQuantity: 2, requiredQuantity: 1));

        $this->assertTrue(true);
    }

    public function testRequiredQuantityBelowForcedQuantityIsRejected(): void
    {
        $this->arrange(requiredProduct: $this->requiredProduct(minPerOrder: 2, maxPerOrder: 5));

        $this->expectException(ValidationException::class);

        $this->service->validateRequestData(self::EVENT_ID, $this->order(ticketQuantity: 1, requiredQuantity: 1));
    }

    public function testOrderWithoutTicketIsNotForced(): void
    {
        $this->arrange(requiredProduct: $this->requiredProduct(), eventSellsTickets: false);

        $this->service->validateRequestData(self::EVENT_ID, [
            'products' => [
                ['product_id' => self::MERCH_ID, 'quantities' => [['price_id' => self::MERCH_PRICE_ID, 'quantity' => 1]]],
            ],
        ]);

        $this->assertTrue(true);
    }

    public function testSoldOutRequiredProductIsNotForced(): void
    {
        $this->arrange(requiredProduct: $this->requiredProduct(), requiredQuantityAvailable: 0);

        $this->service->validateRequestData(self::EVENT_ID, $this->order(ticketQuantity: 1));

        $this->assertTrue(true);
    }

    public function testRequiredProductOutsideItsSaleDatesIsNotForced(): void
    {
        $this->arrange(requiredProduct: $this->requiredProduct()->setSaleEndDate(Carbon::now()->subDay()->toDateTimeString()));

        $this->service->validateRequestData(self::EVENT_ID, $this->order(ticketQuantity: 1));

        $this->assertTrue(true);
    }

    public function testHiddenOrPausedRequiredProductIsNotForced(): void
    {
        $this->arrange(requiredProduct: $this->requiredProduct()->setIsHidden(true));
        $this->service->validateRequestData(self::EVENT_ID, $this->order(ticketQuantity: 1));

        $this->setUp();
        $this->arrange(requiredProduct: $this->requiredProduct()->setIsSalesPaused(true));
        $this->service->validateRequestData(self::EVENT_ID, $this->order(ticketQuantity: 1));

        $this->assertTrue(true);
    }

    public function testDeletedRequiredProductIsNotForced(): void
    {
        $this->arrange(requiredProduct: null);

        $this->service->validateRequestData(self::EVENT_ID, $this->order(ticketQuantity: 1));

        $this->assertTrue(true);
    }

    public function testPerTicketRequiredProductNeedsOnePerTicket(): void
    {
        $this->arrange(requiredProduct: $this->perTicketRequiredProduct());

        $this->service->validateRequestData(self::EVENT_ID, $this->order(ticketQuantity: 3, requiredQuantity: 3));

        $this->assertTrue(true);
    }

    public function testPerTicketRequiredProductWithWrongQuantityIsRejected(): void
    {
        foreach ([1, 2, 4] as $requiredQuantity) {
            $this->setUp();
            $this->arrange(requiredProduct: $this->perTicketRequiredProduct());

            try {
                $this->service->validateRequestData(self::EVENT_ID, $this->order(ticketQuantity: 3, requiredQuantity: $requiredQuantity));
                $this->fail('Expected a ValidationException for quantity ' . $requiredQuantity);
            } catch (ValidationException $e) {
                $this->assertSame(
                    __(':product is included once per ticket (expected quantity: :quantity).', ['product' => 'Option sérénité', 'quantity' => 3]),
                    $e->errors()['products'][0],
                );
            }
        }
    }

    public function testPerTicketRequiredProductMissingIsRejected(): void
    {
        $this->arrange(requiredProduct: $this->perTicketRequiredProduct());

        $this->expectException(ValidationException::class);

        $this->service->validateRequestData(self::EVENT_ID, $this->order(ticketQuantity: 2));
    }

    public function testPerTicketRequiredProductWithoutTicketIsNotForced(): void
    {
        $this->arrange(requiredProduct: $this->perTicketRequiredProduct(), eventSellsTickets: false);

        $this->service->validateRequestData(self::EVENT_ID, [
            'products' => [
                ['product_id' => self::MERCH_ID, 'quantities' => [['price_id' => self::MERCH_PRICE_ID, 'quantity' => 1]]],
            ],
        ]);

        $this->assertTrue(true);
    }

    public function testUnavailablePerTicketRequiredProductIsSkipped(): void
    {
        $this->arrange(requiredProduct: $this->perTicketRequiredProduct()->setIsSalesPaused(true));
        $this->service->validateRequestData(self::EVENT_ID, $this->order(ticketQuantity: 3));

        $this->setUp();
        $this->arrange(requiredProduct: $this->perTicketRequiredProduct(), requiredQuantityAvailable: 0);
        $this->service->validateRequestData(self::EVENT_ID, $this->order(ticketQuantity: 3));

        $this->assertTrue(true);
    }

    public function testPerTicketRequiredProductIsCappedByItsStock(): void
    {
        $this->arrange(requiredProduct: $this->perTicketRequiredProduct(), requiredQuantityAvailable: 2);

        $this->service->validateRequestData(self::EVENT_ID, $this->order(ticketQuantity: 3, requiredQuantity: 2));

        $this->assertTrue(true);
    }

    public function testPerOrderRequiredProductKeepsItsMaximumPerOrder(): void
    {
        $this->arrange(requiredProduct: $this->requiredProduct());

        $this->expectException(ValidationException::class);

        $this->service->validateRequestData(self::EVENT_ID, $this->order(ticketQuantity: 3, requiredQuantity: 3));
    }

    public function testRequiredQuantityDefaultsToOne(): void
    {
        $this->assertSame(1, (new ProductDomainObject())->setMinPerOrder(null)->getRequiredQuantity());
        $this->assertSame(1, (new ProductDomainObject())->setMinPerOrder(0)->getRequiredQuantity());
        $this->assertSame(3, (new ProductDomainObject())->setMinPerOrder(3)->getRequiredQuantity());
    }

    public function testBoxOfficeAndManualAttendeeFlowsDoNotUseTheOnlineOrderValidation(): void
    {
        foreach ([CreateBoxOfficeSaleHandler::class, CreateAttendeeHandler::class] as $handler) {
            $dependencies = collect((new ReflectionClass($handler))->getConstructor()?->getParameters() ?? [])
                ->map(fn ($parameter) => $parameter->getType()?->getName());

            $this->assertNotContains(OrderCreateRequestValidationService::class, $dependencies->all(), $handler);
        }
    }

    private function order(int $ticketQuantity, int $requiredQuantity = 0): array
    {
        $products = [
            ['product_id' => self::TICKET_ID, 'quantities' => [['price_id' => self::TICKET_PRICE_ID, 'quantity' => $ticketQuantity]]],
        ];

        if ($requiredQuantity > 0) {
            $products[] = ['product_id' => self::REQUIRED_ID, 'quantities' => [['price_id' => self::REQUIRED_PRICE_ID, 'quantity' => $requiredQuantity]]];
        }

        return ['products' => $products];
    }

    private function requiredProduct(int $minPerOrder = 1, int $maxPerOrder = 1): ProductDomainObject
    {
        return $this->product(self::REQUIRED_ID, self::REQUIRED_PRICE_ID, 'Option sérénité', 'GENERAL', 0.49)
            ->setIsRequired(true)
            ->setMinPerOrder($minPerOrder)
            ->setMaxPerOrder($maxPerOrder);
    }

    private function perTicketRequiredProduct(): ProductDomainObject
    {
        return $this->requiredProduct(minPerOrder: 1, maxPerOrder: 1)->setRequiredQuantityMode('PER_TICKET');
    }

    private function product(int $id, int $priceId, string $title, string $productType, float $price): ProductDomainObject
    {
        return (new ProductDomainObject())
            ->setId($id)
            ->setEventId(self::EVENT_ID)
            ->setTitle($title)
            ->setType(ProductPriceType::PAID->name)
            ->setProductType($productType)
            ->setMinPerOrder(1)
            ->setMaxPerOrder(10)
            ->setProductPrices(new Collection([
                (new ProductPriceDomainObject())->setId($priceId)->setProductId($id)->setPrice($price),
            ]));
    }

    private function arrange(?ProductDomainObject $requiredProduct, int $requiredQuantityAvailable = 50, bool $eventSellsTickets = true): void
    {
        $event = (new EventDomainObject())
            ->setId(self::EVENT_ID)
            ->setCurrency('EUR')
            ->setEventSettings((new EventSettingDomainObject())->setSingleProductPerOrder(false));

        $this->eventRepository->shouldReceive('loadRelation')->andReturnSelf();
        $this->eventRepository->shouldReceive('findById')->with(self::EVENT_ID)->andReturn($event);

        $ticket = $this->product(self::TICKET_ID, self::TICKET_PRICE_ID, 'Billet', 'TICKET', 10);
        $merch = $this->product(self::MERCH_ID, self::MERCH_PRICE_ID, 'T-shirt', 'GENERAL', 15);
        $catalogue = collect([$ticket, $merch]);
        if ($requiredProduct !== null) {
            $catalogue->push($requiredProduct);
        }

        $this->productRepository->shouldReceive('loadRelation')->andReturnSelf();
        $this->productRepository->shouldReceive('findWhereIn')->andReturnUsing(
            fn (string $field, array $ids) => $catalogue->filter(fn (ProductDomainObject $product) => in_array($product->getId(), $ids, true))->values()
        );
        $this->productRepository->shouldReceive('findWhere')
            ->with(['event_id' => self::EVENT_ID, 'is_required' => true])
            ->andReturn($requiredProduct ? collect([$requiredProduct]) : collect());
        $this->productRepository->shouldReceive('findFirstWhere')
            ->with(['event_id' => self::EVENT_ID, 'product_type' => 'TICKET'])
            ->andReturn($eventSellsTickets ? $ticket : null);

        $this->availabilityService->shouldReceive('getAvailableProductQuantities')->andReturn(
            new AvailableProductQuantitiesResponseDTO(
                productQuantities: collect([
                    $this->quantity(self::TICKET_ID, self::TICKET_PRICE_ID, 100),
                    $this->quantity(self::MERCH_ID, self::MERCH_PRICE_ID, 100),
                    $this->quantity(self::REQUIRED_ID, self::REQUIRED_PRICE_ID, $requiredQuantityAvailable),
                ]),
                capacities: collect(),
            )
        );
    }

    private function quantity(int $productId, int $priceId, int $available): AvailableProductQuantitiesDTO
    {
        return AvailableProductQuantitiesDTO::fromArray([
            'product_id' => $productId,
            'price_id' => $priceId,
            'product_title' => 'Produit',
            'price_label' => null,
            'quantity_available' => $available,
            'quantity_reserved' => 0,
            'initial_quantity_available' => null,
            'capacities' => collect(),
        ]);
    }
}
