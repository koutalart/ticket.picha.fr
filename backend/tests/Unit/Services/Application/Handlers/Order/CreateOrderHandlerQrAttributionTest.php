<?php

namespace Tests\Unit\Services\Application\Handlers\Order;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\Generated\QrLinkDomainObjectAbstract;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrderItemDomainObject;
use HiEvents\DomainObjects\QrLinkDomainObject;
use HiEvents\DomainObjects\Status\EventStatus;
use HiEvents\DomainObjects\Status\QrLinkStatus;
use HiEvents\Repository\Interfaces\AffiliateRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\PromoCodeRepositoryInterface;
use HiEvents\Repository\Interfaces\QrLinkRepositoryInterface;
use HiEvents\Services\Application\Handlers\Order\CreateOrderHandler;
use HiEvents\Services\Application\Handlers\Order\DTO\CreateOrderPublicDTO;
use HiEvents\Services\Application\Handlers\Order\DTO\ProductOrderDetailsDTO;
use HiEvents\Services\Domain\Contact\OrderContactAttributionService;
use HiEvents\Services\Domain\Order\OrderItemProcessingService;
use HiEvents\Services\Domain\Order\OrderManagementService;
use HiEvents\Services\Domain\Order\RefundProtection\OrderRefundProtectionService;
use HiEvents\Services\Domain\Product\AvailableProductQuantitiesFetchService;
use HiEvents\Services\Domain\Product\DTO\AvailableProductQuantitiesDTO;
use HiEvents\Services\Domain\Product\DTO\AvailableProductQuantitiesResponseDTO;
use HiEvents\Services\Domain\Product\DTO\OrderProductPriceDTO;
use HiEvents\Services\Domain\PromoCode\PromoCodeUsageValidationService;
use Illuminate\Database\DatabaseManager;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class CreateOrderHandlerQrAttributionTest extends TestCase
{
    private const EVENT_ID = 7;

    private const ORGANIZER_ID = 4;

    private QrLinkRepositoryInterface|MockInterface $qrLinkRepository;

    private OrderManagementService|MockInterface $orderManagementService;

    private CreateOrderHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->qrLinkRepository = Mockery::mock(QrLinkRepositoryInterface::class);
        $this->orderManagementService = Mockery::mock(OrderManagementService::class);

        $databaseManager = Mockery::mock(DatabaseManager::class);
        $databaseManager->shouldReceive('transaction')->andReturnUsing(fn ($callback) => $callback());
        $databaseManager->shouldReceive('statement')->andReturn(true);

        $eventSettings = Mockery::mock(EventSettingDomainObject::class);
        $eventSettings->shouldReceive('getOrderTimeoutInMinutes')->andReturn(15);

        $event = Mockery::mock(EventDomainObject::class);
        $event->shouldReceive('getId')->andReturn(self::EVENT_ID);
        $event->shouldReceive('getOrganizerId')->andReturn(self::ORGANIZER_ID);
        $event->shouldReceive('getStatus')->andReturn(EventStatus::LIVE->name);
        $event->shouldReceive('getEventSettings')->andReturn($eventSettings);

        $eventRepository = Mockery::mock(EventRepositoryInterface::class);
        $eventRepository->shouldReceive('loadRelation')->andReturnSelf();
        $eventRepository->shouldReceive('findById')->with(self::EVENT_ID)->andReturn($event);

        $availabilityService = Mockery::mock(AvailableProductQuantitiesFetchService::class);
        $availabilityService->shouldReceive('getAvailableProductQuantities')
            ->andReturn(new AvailableProductQuantitiesResponseDTO(
                productQuantities: collect([
                    AvailableProductQuantitiesDTO::fromArray([
                        'product_id' => 10,
                        'price_id' => 100,
                        'product_title' => 'Halloween',
                        'price_label' => null,
                        'quantity_available' => 50,
                        'quantity_reserved' => 0,
                        'initial_quantity_available' => 100,
                    ]),
                ]),
            ));

        $orderItemProcessingService = Mockery::mock(OrderItemProcessingService::class);
        $orderItemProcessingService->shouldReceive('process')
            ->andReturn(collect([Mockery::mock(OrderItemDomainObject::class)]));

        $this->orderManagementService->shouldReceive('deleteExistingOrders');
        $this->orderManagementService->shouldReceive('updateOrderTotals')->andReturnUsing(fn ($order) => $order);

        $this->handler = new CreateOrderHandler(
            $eventRepository,
            Mockery::mock(PromoCodeRepositoryInterface::class),
            Mockery::mock(PromoCodeUsageValidationService::class),
            Mockery::mock(AffiliateRepositoryInterface::class),
            $this->orderManagementService,
            $orderItemProcessingService,
            $availabilityService,
            $databaseManager,
            $this->qrLinkRepository,
            Mockery::mock(OrderContactAttributionService::class)->shouldReceive('resolveContactId')->andReturn(null)->getMock(),
            Mockery::mock(OrderRefundProtectionService::class)->shouldReceive('syncAfterOrderCreation')->andReturnUsing(fn ($order) => $order)->getMock(),
        );
    }

    protected function tearDown(): void
    {
        $this->addToAssertionCount(Mockery::getContainer()->mockery_getExpectationCount());
        Mockery::close();
        parent::tearDown();
    }

    public function test_order_keeps_the_qr_link_and_tracking_context_of_the_ad_click(): void
    {
        $qrLink = (new QrLinkDomainObject)->setId(12)->setShortId('7b4dbdd');
        $trackingContext = ['consent' => false, 'qr_code' => '7b4dbdd', 'last_touch' => ['utm_source' => 'facebook']];

        $this->qrLinkRepository->shouldReceive('findFirstWhere')
            ->with([
                QrLinkDomainObjectAbstract::SHORT_ID => '7b4dbdd',
                QrLinkDomainObjectAbstract::ORGANIZER_ID => self::ORGANIZER_ID,
                QrLinkDomainObjectAbstract::STATUS => QrLinkStatus::ACTIVE->value,
            ])
            ->andReturn($qrLink);

        $this->orderManagementService->shouldReceive('createNewOrder')
            ->once()
            ->withArgs(fn ($eventId, $event, $timeout, $locale, $promo, $affiliate, $session, $qr, $context) => $qr === $qrLink && $context === $trackingContext)
            ->andReturn(Mockery::mock(OrderDomainObject::class));

        $this->handler->handle(self::EVENT_ID, $this->orderDto(' 7B4DBDD ', $trackingContext));
    }

    public function test_an_order_created_again_after_an_abandoned_one_keeps_the_qr_link(): void
    {
        $qrLink = (new QrLinkDomainObject)->setId(12)->setShortId('7b4dbdd');
        $this->qrLinkRepository->shouldReceive('findFirstWhere')->twice()->andReturn($qrLink);

        $this->orderManagementService->shouldReceive('createNewOrder')
            ->twice()
            ->withArgs(fn ($eventId, $event, $timeout, $locale, $promo, $affiliate, $session, $qr) => $qr === $qrLink)
            ->andReturn(Mockery::mock(OrderDomainObject::class));

        $this->handler->handle(self::EVENT_ID, $this->orderDto('7b4dbdd', null));
        $this->handler->handle(self::EVENT_ID, $this->orderDto('7b4dbdd', null));
    }

    public function test_order_without_qr_code_is_not_attributed(): void
    {
        $this->qrLinkRepository->shouldNotReceive('findFirstWhere');

        $this->orderManagementService->shouldReceive('createNewOrder')
            ->once()
            ->withArgs(fn ($eventId, $event, $timeout, $locale, $promo, $affiliate, $session, $qr) => $qr === null)
            ->andReturn(Mockery::mock(OrderDomainObject::class));

        $this->handler->handle(self::EVENT_ID, $this->orderDto(null, null));
    }

    private function orderDto(?string $qrCode, ?array $trackingContext): CreateOrderPublicDTO
    {
        return CreateOrderPublicDTO::fromArray([
            'is_user_authenticated' => false,
            'session_identifier' => 'session-qr',
            'order_locale' => 'fr',
            'qr_link_code' => $qrCode,
            'tracking_context' => $trackingContext,
            'products' => collect([
                ProductOrderDetailsDTO::fromArray([
                    'product_id' => 10,
                    'quantities' => collect([
                        OrderProductPriceDTO::fromArray(['price_id' => 100, 'quantity' => 1]),
                    ]),
                ]),
            ]),
        ]);
    }
}
