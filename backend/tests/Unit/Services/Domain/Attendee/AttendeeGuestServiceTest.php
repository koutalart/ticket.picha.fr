<?php

namespace Tests\Unit\Services\Domain\Attendee;

use Carbon\Carbon;
use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\Enums\CapacityChangeDirection;
use HiEvents\DomainObjects\Enums\ProductPriceType;
use HiEvents\DomainObjects\Enums\ProductType;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrderItemDomainObject;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\ProductPriceDomainObject;
use HiEvents\DomainObjects\Status\AttendeeStatus;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Events\CapacityChangedEvent;
use HiEvents\Exceptions\AttendeeGuestNotAllowedException;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderItemRepositoryInterface;
use HiEvents\Repository\Interfaces\ProductPriceRepositoryInterface;
use HiEvents\Repository\Interfaces\ProductRepositoryInterface;
use HiEvents\Repository\Interfaces\QuestionAnswerRepositoryInterface;
use HiEvents\Repository\Interfaces\QuestionRepositoryInterface;
use HiEvents\Services\Domain\Attendee\AttendeeGuestService;
use HiEvents\Services\Domain\Attendee\AttendeePublicIdGenerator;
use HiEvents\Services\Domain\Attendee\DTO\AttendeeAttendanceContextDTO;
use HiEvents\Services\Domain\Attendee\DTO\AttendeeGuestDataDTO;
use HiEvents\Services\Domain\EventStatistics\EventStatisticsReactivationService;
use HiEvents\Services\Domain\Product\AvailableProductQuantitiesFetchService;
use HiEvents\Services\Domain\Product\DTO\AvailableProductQuantitiesDTO;
use HiEvents\Services\Domain\Product\DTO\AvailableProductQuantitiesResponseDTO;
use HiEvents\Services\Domain\Product\ProductQuantityUpdateService;
use Illuminate\Contracts\Events\Dispatcher;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class AttendeeGuestServiceTest extends TestCase
{
    private MockInterface|AttendeeRepositoryInterface $attendeeRepository;
    private MockInterface|ProductRepositoryInterface $productRepository;
    private MockInterface|ProductPriceRepositoryInterface $productPriceRepository;
    private MockInterface|OrderItemRepositoryInterface $orderItemRepository;
    private MockInterface|QuestionRepositoryInterface $questionRepository;
    private MockInterface|QuestionAnswerRepositoryInterface $questionAnswerRepository;
    private MockInterface|AvailableProductQuantitiesFetchService $availableQuantitiesService;
    private MockInterface|ProductQuantityUpdateService $productQuantityUpdateService;
    private MockInterface|EventStatisticsReactivationService $eventStatisticsReactivationService;
    private MockInterface|AttendeePublicIdGenerator $publicIdGenerator;
    private MockInterface|Dispatcher $eventDispatcher;
    private AttendeeGuestService $service;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.customer_locale' => 'fr']);
        Carbon::setTestNow('2026-10-22 10:00:00');

        $this->attendeeRepository = Mockery::mock(AttendeeRepositoryInterface::class);
        $this->productRepository = Mockery::mock(ProductRepositoryInterface::class);
        $this->productPriceRepository = Mockery::mock(ProductPriceRepositoryInterface::class);
        $this->orderItemRepository = Mockery::mock(OrderItemRepositoryInterface::class);
        $this->questionRepository = Mockery::mock(QuestionRepositoryInterface::class);
        $this->questionAnswerRepository = Mockery::mock(QuestionAnswerRepositoryInterface::class);
        $this->availableQuantitiesService = Mockery::mock(AvailableProductQuantitiesFetchService::class);
        $this->productQuantityUpdateService = Mockery::mock(ProductQuantityUpdateService::class);
        $this->eventStatisticsReactivationService = Mockery::mock(EventStatisticsReactivationService::class);
        $this->publicIdGenerator = Mockery::mock(AttendeePublicIdGenerator::class);
        $this->eventDispatcher = Mockery::mock(Dispatcher::class);

        $this->productRepository->shouldReceive('loadRelation')->andReturnSelf();
        $this->questionRepository->shouldReceive('loadRelation')->andReturnSelf();
        $this->productPriceRepository->shouldReceive('lockForUpdateById')->with(30)->andReturn(new ProductPriceDomainObject())->byDefault();

        $this->service = new AttendeeGuestService(
            attendeeRepository: $this->attendeeRepository,
            productRepository: $this->productRepository,
            productPriceRepository: $this->productPriceRepository,
            orderItemRepository: $this->orderItemRepository,
            questionRepository: $this->questionRepository,
            questionAnswerRepository: $this->questionAnswerRepository,
            availableQuantitiesService: $this->availableQuantitiesService,
            productQuantityUpdateService: $this->productQuantityUpdateService,
            eventStatisticsReactivationService: $this->eventStatisticsReactivationService,
            publicIdGenerator: $this->publicIdGenerator,
            eventDispatcher: $this->eventDispatcher,
        );
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function testAvailabilityIsOpenForAFreeTicketWithAPlaceLeft(): void
    {
        $this->expectProduct($this->createProduct());
        $this->expectActiveAttendees(1);
        $this->expectStock(50);

        $availability = $this->service->getAvailability($this->createContext());

        $this->assertTrue($availability->canAddGuest);
        $this->assertSame(1, $availability->placesRemaining);
        $this->assertSame(2, $availability->maxPerOrder);
        $this->assertNull($availability->reason);
    }

    public function testAddGuestCreatesAnAttendeeInTheSameOrderAndUpdatesStock(): void
    {
        $this->expectProduct($this->createProduct());
        $this->expectActiveAttendees(1);
        $this->expectStock(50);
        $this->publicIdGenerator->shouldReceive('generateUnique')->once()->andReturn('A-PUBLIC');

        $guest = (new AttendeeDomainObject())
            ->setId(6)
            ->setEventId(2)
            ->setOrderId(40)
            ->setProductId(20)
            ->setProductPriceId(30);

        $this->attendeeRepository->shouldReceive('create')
            ->once()
            ->withArgs(function (array $attributes): bool {
                return $attributes['order_id'] === 40
                    && $attributes['event_id'] === 2
                    && $attributes['product_id'] === 20
                    && $attributes['product_price_id'] === 30
                    && $attributes['status'] === AttendeeStatus::ACTIVE->name
                    && $attributes['first_name'] === 'Amina'
                    && $attributes['last_name'] === 'Said'
                    && $attributes['email'] === 'buyer@example.com'
                    && $attributes['public_id'] === 'A-PUBLIC'
                    && str_starts_with($attributes['short_id'], 'a_');
            })
            ->andReturn($guest);

        $this->orderItemRepository->shouldReceive('findFirstWhere')
            ->once()
            ->with(['order_id' => 40, 'product_price_id' => 30])
            ->andReturn((new OrderItemDomainObject())->setId(70));
        $this->orderItemRepository->shouldReceive('increment')->once()->with(70, 'quantity');

        $this->productQuantityUpdateService->shouldReceive('increaseQuantitySold')->once()->with(30);
        $this->eventStatisticsReactivationService->shouldReceive('incrementForReactivatedAttendee')
            ->once()
            ->with(2, '2026-10-01 12:00:00', 1, 1);

        $this->eventDispatcher->shouldReceive('dispatch')
            ->once()
            ->withArgs(fn(CapacityChangedEvent $event) => $event->direction === CapacityChangeDirection::DECREASED
                && $event->productPriceId === 30);

        $result = $this->service->addGuest(
            $this->createContext(),
            new AttendeeGuestDataDTO(firstName: 'Amina', lastName: 'Said', phone: null),
        );

        $this->assertSame($guest, $result);
    }

    public function testAddGuestIsRefusedWhenMaxPerOrderIsReached(): void
    {
        $this->expectProduct($this->createProduct());
        $this->expectActiveAttendees(2);
        $this->expectNothingCreated();

        $this->expectException(AttendeeGuestNotAllowedException::class);
        $this->expectExceptionMessage('Cette inscription compte déjà le nombre maximum de places.');

        $this->service->addGuest($this->createContext(), $this->guestData());
    }

    public function testAddGuestIsRefusedForAPaidProduct(): void
    {
        $this->expectProduct($this->createProduct(price: 15.0, type: ProductPriceType::PAID));
        $this->expectNothingCreated();

        $this->expectException(AttendeeGuestNotAllowedException::class);
        $this->expectExceptionMessage("Un invité ne peut être ajouté qu'à une inscription gratuite.");

        $this->service->addGuest($this->createContext(), $this->guestData());
    }

    public function testAddGuestIsRefusedWhenNoStockIsLeft(): void
    {
        $this->expectProduct($this->createProduct());
        $this->expectActiveAttendees(1);
        $this->expectStock(0);
        $this->expectNothingCreated();

        $this->expectException(AttendeeGuestNotAllowedException::class);
        $this->expectExceptionMessage("Il n'y a plus de place disponible pour ce billet.");

        $this->service->addGuest($this->createContext(), $this->guestData());
    }

    public function testAddGuestIsRefusedForACancelledOrder(): void
    {
        $this->expectProduct($this->createProduct());
        $this->expectNothingCreated();

        $this->expectException(AttendeeGuestNotAllowedException::class);

        $this->service->addGuest(
            $this->createContext(orderStatus: OrderStatus::CANCELLED->name),
            $this->guestData(),
        );
    }

    public function testAvailabilityIsClosedOnceTheEventIsOver(): void
    {
        $this->expectProduct($this->createProduct());

        $availability = $this->service->getAvailability($this->createContext(startDate: '2026-10-01 18:00:00'));

        $this->assertFalse($availability->canAddGuest);
        $this->assertSame(0, $availability->placesRemaining);
    }

    public function testAvailabilityIsClosedWithoutAMaxPerOrder(): void
    {
        $this->expectProduct($this->createProduct(maxPerOrder: null));
        $this->expectActiveAttendees(1);

        $availability = $this->service->getAvailability($this->createContext());

        $this->assertFalse($availability->canAddGuest);
    }

    private function guestData(): AttendeeGuestDataDTO
    {
        return new AttendeeGuestDataDTO(firstName: 'Amina', lastName: 'Said', phone: null);
    }

    private function expectNothingCreated(): void
    {
        $this->attendeeRepository->shouldNotReceive('create');
        $this->productQuantityUpdateService->shouldNotReceive('increaseQuantitySold');
        $this->eventStatisticsReactivationService->shouldNotReceive('incrementForReactivatedAttendee');
    }

    private function expectProduct(ProductDomainObject $product): void
    {
        $this->productRepository->shouldReceive('findFirstWhere')
            ->with(['id' => 20, 'event_id' => 2])
            ->andReturn($product);
    }

    private function expectActiveAttendees(int $count): void
    {
        $this->attendeeRepository->shouldReceive('countWhere')
            ->with([
                'order_id' => 40,
                'product_id' => 20,
                'status' => AttendeeStatus::ACTIVE->name,
            ])
            ->andReturn($count);
    }

    private function expectStock(int $quantity): void
    {
        $this->availableQuantitiesService->shouldReceive('getAvailableProductQuantities')
            ->with(2, true)
            ->andReturn(new AvailableProductQuantitiesResponseDTO(
                productQuantities: collect([
                    new AvailableProductQuantitiesDTO(
                        product_id: 20,
                        price_id: 30,
                        product_title: 'Invitation pour 2 personnes',
                        price_label: null,
                        quantity_available: $quantity,
                        quantity_reserved: 0,
                        initial_quantity_available: 100,
                    ),
                ]),
            ));
    }

    private function createProduct(
        float            $price = 0.0,
        ProductPriceType $type = ProductPriceType::FREE,
        ?int             $maxPerOrder = 2,
    ): ProductDomainObject
    {
        return (new ProductDomainObject())
            ->setId(20)
            ->setEventId(2)
            ->setTitle('Invitation pour 2 personnes')
            ->setProductType(ProductType::TICKET->name)
            ->setType($type->name)
            ->setMaxPerOrder($maxPerOrder)
            ->setProductPrices(collect([
                (new ProductPriceDomainObject())->setId(30)->setProductId(20)->setPrice($price),
            ]));
    }

    private function createContext(
        string $orderStatus = 'COMPLETED',
        string $startDate = '2026-11-15 18:00:00',
    ): AttendeeAttendanceContextDTO
    {
        $attendee = (new AttendeeDomainObject())
            ->setId(5)
            ->setEventId(2)
            ->setOrderId(40)
            ->setProductId(20)
            ->setProductPriceId(30)
            ->setShortId('a_short')
            ->setStatus(AttendeeStatus::ACTIVE->name);

        $event = (new EventDomainObject())
            ->setId(2)
            ->setTimezone('Indian/Mayotte')
            ->setStartDate($startDate);

        $order = (new OrderDomainObject())
            ->setId(40)
            ->setEventId(2)
            ->setEmail('buyer@example.com')
            ->setLocale('fr')
            ->setCreatedAt('2026-10-01 12:00:00')
            ->setStatus($orderStatus);

        return new AttendeeAttendanceContextDTO(
            attendee: $attendee,
            event: $event,
            order: $order,
            locale: 'fr',
        );
    }
}
