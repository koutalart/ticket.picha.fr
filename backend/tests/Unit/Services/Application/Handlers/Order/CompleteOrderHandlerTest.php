<?php

namespace Tests\Unit\Services\Application\Handlers\Order;

use Carbon\Carbon;
use Exception;
use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\Enums\AgeRestrictionRule;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\Exceptions\AttendeeAgeRestrictionException;
use HiEvents\Services\Domain\Attendee\AttendeeAgeRestrictionService;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrderItemDomainObject;
use HiEvents\DomainObjects\ProductPriceDomainObject;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Repository\Interfaces\AffiliateRepositoryInterface;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\EventSettingsRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\ProductPriceRepositoryInterface;
use HiEvents\Repository\Interfaces\QuestionAnswerRepositoryInterface;
use HiEvents\Services\Application\Handlers\Order\CompleteOrderHandler;
use HiEvents\Services\Application\Handlers\Order\DTO\CompleteOrderDTO;
use HiEvents\Services\Application\Handlers\Order\DTO\CompleteOrderOrderDTO;
use HiEvents\Services\Application\Handlers\Order\DTO\CompleteOrderProductDataDTO;
use HiEvents\Services\Domain\Product\ProductQuantityUpdateService;
use HiEvents\Services\Domain\Question\File\QuestionAnswerFileService;
use HiEvents\DomainObjects\QuestionAnswerDomainObject;
use HiEvents\DomainObjects\QuestionAnswerFileDomainObject;
use HiEvents\Services\Application\Handlers\Order\DTO\OrderQuestionsDTO;
use HiEvents\Services\Infrastructure\DomainEvents\DomainEventDispatcherService;
use HiEvents\Services\Infrastructure\DomainEvents\Enums\DomainEventType;
use HiEvents\Services\Infrastructure\DomainEvents\Events\OrderEvent;
use HiEvents\Services\Infrastructure\Session\CheckoutSessionManagementService;
use Illuminate\Database\Connection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Mockery\MockInterface;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Tests\TestCase;

class CompleteOrderHandlerTest extends TestCase
{
    private OrderRepositoryInterface|MockInterface $orderRepository;
    private AttendeeRepositoryInterface|MockInterface $attendeeRepository;
    private QuestionAnswerRepositoryInterface|MockInterface $questionAnswersRepository;
    private ProductQuantityUpdateService|MockInterface $productQuantityUpdateService;
    private ProductPriceRepositoryInterface|MockInterface $productPriceRepository;
    private CompleteOrderHandler $completeOrderHandler;
    private DomainEventDispatcherService $domainEventDispatcherService;
    private AffiliateRepositoryInterface|MockInterface $affiliateRepository;
    private EventSettingsRepositoryInterface $eventSettingsRepository;
    private CheckoutSessionManagementService|MockInterface $sessionManagementService;
    private QuestionAnswerFileService|MockInterface $questionAnswerFileService;
    private EventRepositoryInterface|MockInterface $eventRepository;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
        Bus::fake();
        DB::shouldReceive('transaction')->andReturnUsing(fn($callback) => $callback(Mockery::mock(Connection::class)));

        $this->orderRepository = Mockery::mock(OrderRepositoryInterface::class);
        $this->attendeeRepository = Mockery::mock(AttendeeRepositoryInterface::class);
        $this->questionAnswersRepository = Mockery::mock(QuestionAnswerRepositoryInterface::class);
        $this->productQuantityUpdateService = Mockery::mock(ProductQuantityUpdateService::class);
        $this->productPriceRepository = Mockery::mock(ProductPriceRepositoryInterface::class);
        $this->domainEventDispatcherService = Mockery::mock(DomainEventDispatcherService::class);
        $this->affiliateRepository = Mockery::mock(AffiliateRepositoryInterface::class);
        $this->eventSettingsRepository = Mockery::mock(EventSettingsRepositoryInterface::class);
        $this->sessionManagementService = Mockery::mock(CheckoutSessionManagementService::class);
        $this->sessionManagementService->shouldReceive('verifySession')->andReturn(true)->byDefault();
        $this->questionAnswerFileService = Mockery::mock(QuestionAnswerFileService::class);
        $this->questionAnswerFileService->shouldReceive('getUnattachedFilesForOrder')->andReturn(new Collection())->byDefault();
        $this->eventRepository = Mockery::mock(EventRepositoryInterface::class);

        $this->completeOrderHandler = new CompleteOrderHandler(
            $this->orderRepository,
            $this->affiliateRepository,
            $this->attendeeRepository,
            $this->questionAnswersRepository,
            $this->productQuantityUpdateService,
            $this->productPriceRepository,
            $this->domainEventDispatcherService,
            $this->eventSettingsRepository,
            $this->sessionManagementService,
            $this->questionAnswerFileService,
            $this->eventRepository,
            new AttendeeAgeRestrictionService(),
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testHandleSuccessfullyCompletesOrder(): void
    {
        $orderShortId = 'ABC123';
        $orderData = $this->createMockCompleteOrderDTO();
        $order = $this->createMockOrder();
        $updatedOrder = $this->createMockOrder();

        $this->orderRepository->shouldReceive('findByShortId')->with($orderShortId)->andReturn($order);
        $this->orderRepository->shouldReceive('loadRelation')->andReturnSelf();
        $this->orderRepository->shouldReceive('updateFromArray')->andReturn($updatedOrder);
        $this->orderRepository->shouldReceive('loadRelation')->andReturnSelf();

        $this->productPriceRepository->shouldReceive('findWhereIn')->andReturn(new Collection([$this->createMockProductPrice()]));

        $this->attendeeRepository->shouldReceive('insert')->andReturn(true);
        $this->attendeeRepository->shouldReceive('findWhereIn')->andReturn(new Collection([$this->createMockAttendee()]));

        $this->productQuantityUpdateService->shouldReceive('updateQuantitiesFromOrder');

        $this->eventSettingsRepository->shouldReceive('findFirstWhere')->andReturn($this->createMockEventSetting());

        $this->completeOrderHandler->handle($orderShortId, $orderData);

        $this->assertTrue(true);
    }

    public function testHandleThrowsResourceNotFoundExceptionWhenOrderNotFound(): void
    {
        $this->expectException(ResourceNotFoundException::class);

        $orderShortId = 'NONEXISTENT';
        $orderData = $this->createMockCompleteOrderDTO();

        $this->eventSettingsRepository->shouldReceive('findFirstWhere')->andReturn($this->createMockEventSetting());
        $this->orderRepository->shouldReceive('findByShortId')->with($orderShortId)->andReturnNull();
        $this->orderRepository->shouldReceive('loadRelation')->andReturnSelf();

        $this->completeOrderHandler->handle($orderShortId, $orderData);
    }

    public function testHandleThrowsResourceConflictExceptionWhenOrderAlreadyProcessed(): void
    {
        $this->expectException(ResourceConflictException::class);
        $this->expectExceptionMessage('This order has already been processed');

        $orderShortId = 'ABC123';
        $orderData = $this->createMockCompleteOrderDTO();

        $order = $this->createMockOrder(OrderStatus::COMPLETED);
        $order->setEmail('d@d.com');
        $order->setTotalGross(0);

        $this->eventSettingsRepository->shouldReceive('findFirstWhere')->andReturn($this->createMockEventSetting());
        $this->orderRepository->shouldReceive('findByShortId')->with($orderShortId)->andReturn($order);
        $this->orderRepository->shouldReceive('loadRelation')->andReturnSelf();

        $this->completeOrderHandler->handle($orderShortId, $orderData);
    }

    public function testHandleThrowsResourceConflictExceptionWhenOrderExpired(): void
    {
        $this->expectException(ResourceConflictException::class);

        $orderShortId = 'ABC123';
        $orderData = $this->createMockCompleteOrderDTO();
        $order = $this->createMockOrder();
        $order->setEmail('d@d.com');
        $order->setReservedUntil(Carbon::now()->subHour()->toDateTimeString());
        $order->setTotalGross(100);

        $this->eventSettingsRepository->shouldReceive('findFirstWhere')->andReturn($this->createMockEventSetting());
        $this->orderRepository->shouldReceive('findByShortId')->with($orderShortId)->andReturn($order);
        $this->orderRepository->shouldReceive('loadRelation')->andReturnSelf();

        $this->completeOrderHandler->handle($orderShortId, $orderData);
    }

    public function testHandleUpdatesProductQuantitiesForFreeOrder(): void
    {
        Event::fake();

        $orderShortId = 'ABC123';
        $orderData = $this->createMockCompleteOrderDTO();
        $order = $this->createMockOrder();
        $updatedOrder = $this->createMockOrder(OrderStatus::COMPLETED);

        $order->setTotalGross(0);
        $this->orderRepository->shouldReceive('findByShortId')->with($orderShortId)->andReturn($order);
        $this->orderRepository->shouldReceive('loadRelation')->andReturnSelf();
        $this->orderRepository->shouldReceive('updateFromArray')->andReturn($updatedOrder);

        $this->productPriceRepository->shouldReceive('findWhereIn')->andReturn(new Collection([$this->createMockProductPrice()]));

        $this->attendeeRepository->shouldReceive('insert')->andReturn(true);
        $this->attendeeRepository->shouldReceive('findWhereIn')->andReturn(new Collection([$this->createMockAttendee()]));

        $this->productQuantityUpdateService->shouldReceive('updateQuantitiesFromOrder')->once();

        $this->eventSettingsRepository->shouldReceive('findFirstWhere')->andReturn($this->createMockEventSetting());

        $this->domainEventDispatcherService->shouldReceive('dispatch')
            ->withArgs(function (OrderEvent $event) use ($order) {
                return $event->type === DomainEventType::ORDER_CREATED
                    && $event->orderId === $order->getId();
            })
            ->once();

        $order = $this->completeOrderHandler->handle($orderShortId, $orderData);

        $this->assertSame($order->getStatus(), OrderStatus::COMPLETED->name);
    }

    public function testHandleDoesNotUpdateProductQuantitiesForPaidOrder(): void
    {
        $orderShortId = 'ABC123';
        $orderData = $this->createMockCompleteOrderDTO();
        $order = $this->createMockOrder();
        $updatedOrder = $this->createMockOrder();

        $order->setTotalGross(10);

        $this->orderRepository->shouldReceive('findByShortId')->with($orderShortId)->andReturn($order);
        $this->orderRepository->shouldReceive('loadRelation')->andReturnSelf();
        $this->orderRepository->shouldReceive('updateFromArray')->andReturn($updatedOrder);

        $this->productPriceRepository->shouldReceive('findWhereIn')->andReturn(new Collection([$this->createMockProductPrice()]));

        $this->attendeeRepository->shouldReceive('insert')->andReturn(true);
        $this->attendeeRepository->shouldReceive('findWhereIn')->andReturn(new Collection([$this->createMockAttendee()]));

        $this->productQuantityUpdateService->shouldNotReceive('updateQuantitiesFromOrder');

        $this->eventSettingsRepository->shouldReceive('findFirstWhere')->andReturn($this->createMockEventSetting());

        $this->completeOrderHandler->handle($orderShortId, $orderData);

        $this->expectNotToPerformAssertions();
    }

    public function testHandleThrowsExceptionWhenAttendeeInsertFails(): void
    {
        $this->expectException(Exception::class);

        $orderShortId = 'ABC123';
        $orderData = $this->createMockCompleteOrderDTO();
        $order = $this->createMockOrder();
        $updatedOrder = $this->createMockOrder();

        $this->eventSettingsRepository->shouldReceive('findFirstWhere')->andReturn($this->createMockEventSetting());
        $this->orderRepository->shouldReceive('findByShortId')->with($orderShortId)->andReturn($order);
        $this->orderRepository->shouldReceive('loadRelation')->andReturnSelf();
        $this->orderRepository->shouldReceive('updateFromArray')->andReturn($updatedOrder);

        $this->productPriceRepository->shouldReceive('findWhereIn')->andReturn(new Collection([$this->createMockProductPrice()]));

        $this->attendeeRepository->shouldReceive('insert')->andReturn(false);

        $this->completeOrderHandler->handle($orderShortId, $orderData);
    }

    public function testExceptionIsThrowWhenAttendeeCountDoesNotMatchOrderItemsCount(): void
    {
        $this->expectException(ResourceConflictException::class);
        $this->expectExceptionMessage('The number of attendees does not match the number of tickets in the order');

        $orderShortId = 'ABC123';
        $orderData = $this->createMockCompleteOrderDTO();
        $order = $this->createMockOrder();
        $updatedOrder = $this->createMockOrder();

        $order->getOrderItems()->first()->setQuantity(2);

        $this->eventSettingsRepository->shouldReceive('findFirstWhere')->andReturn($this->createMockEventSetting());
        $this->orderRepository->shouldReceive('findByShortId')->with($orderShortId)->andReturn($order);
        $this->orderRepository->shouldReceive('loadRelation')->andReturnSelf();
        $this->orderRepository->shouldReceive('updateFromArray')->andReturn($updatedOrder);

        $this->productPriceRepository->shouldReceive('findWhereIn')->andReturn(new Collection([$this->createMockProductPrice()]));

        $this->attendeeRepository->shouldReceive('insert')->andReturn(true);
        $this->attendeeRepository->shouldReceive('findWhere')->andReturn(new Collection());

        $this->completeOrderHandler->handle($orderShortId, $orderData);
    }

    public function testFileAnswerIsStoredWithFilenameAndAttachedToTheAnswer(): void
    {
        $order = $this->createMockOrder();
        $file = (new QuestionAnswerFileDomainObject())
            ->setId(42)
            ->setOrderId(1)
            ->setQuestionId(7)
            ->setOriginalFilename('cv.pdf');

        $orderData = new CompleteOrderDTO(
            order: new CompleteOrderOrderDTO(
                first_name: 'John',
                last_name: 'Doe',
                email: 'john@example.com',
                questions: new Collection([new OrderQuestionsDTO(question_id: 7, response: ['answer' => 42])]),
            ),
            products: new Collection([new CompleteOrderProductDataDTO(
                product_price_id: 1,
                first_name: 'John',
                last_name: 'Doe',
                email: 'john@example.com'
            )]),
            event_id: 1,
        );

        $this->orderRepository->shouldReceive('findByShortId')->andReturn($order);
        $this->orderRepository->shouldReceive('loadRelation')->andReturnSelf();
        $this->orderRepository->shouldReceive('updateFromArray')->andReturn($this->createMockOrder());
        $this->productPriceRepository->shouldReceive('findWhereIn')->andReturn(new Collection([$this->createMockProductPrice()]));
        $this->attendeeRepository->shouldReceive('insert')->andReturn(true);
        $this->attendeeRepository->shouldReceive('findWhereIn')->andReturn(new Collection([$this->createMockAttendee()]));
        $this->eventSettingsRepository->shouldReceive('findFirstWhere')->andReturn($this->createMockEventSetting());

        $this->productQuantityUpdateService->shouldReceive('updateQuantitiesFromOrder')->zeroOrMoreTimes();
        $this->domainEventDispatcherService->shouldReceive('dispatch')->zeroOrMoreTimes();

        $this->questionAnswerFileService->shouldReceive('getUnattachedFilesForOrder')->with(1)->andReturn(new Collection([$file]));
        $this->questionAnswerFileService->shouldReceive('getUnattachedFileForAnswer')->once()->with(1, 7, 42)->andReturn($file);
        $this->questionAnswerFileService->shouldReceive('buildAnswer')->once()->with($file)->andReturn(['file_id' => 42, 'name' => 'cv.pdf']);
        $this->questionAnswerFileService->shouldReceive('attachToAnswer')->once()->with(42, 99);

        $this->questionAnswersRepository->shouldReceive('create')
            ->once()
            ->with([
                'question_id' => 7,
                'answer' => ['file_id' => 42, 'name' => 'cv.pdf'],
                'order_id' => 1,
            ])
            ->andReturn((new QuestionAnswerDomainObject())->setId(99));

        $this->completeOrderHandler->handle('ABC123', $orderData);

        $this->assertTrue(true);
    }

    public function testMinorAloneIsRejectedWhenMinorsMustBeAccompanied(): void
    {
        $this->prepareAgeRestrictedOrder(AgeRestrictionRule::ACCOMPANIED_MINORS);
        $this->attendeeRepository->shouldNotReceive('insert');

        try {
            $this->completeOrderHandler->handle('ABC123', $this->createMockCompleteOrderDTO('2012-05-01'));
            $this->fail('An age restriction exception was expected');
        } catch (AttendeeAgeRestrictionException $e) {
            $this->assertArrayHasKey('products.0.birth_date', $e->getErrors());
        }
    }

    public function testMissingBirthDateIsRejectedWhenAgeRuleIsActive(): void
    {
        $this->prepareAgeRestrictedOrder(AgeRestrictionRule::MINIMUM_AGE);
        $this->attendeeRepository->shouldNotReceive('insert');

        $this->expectException(AttendeeAgeRestrictionException::class);

        $this->completeOrderHandler->handle('ABC123', $this->createMockCompleteOrderDTO());
    }

    public function testAdultIsAcceptedAndBirthDateIsStored(): void
    {
        $this->prepareAgeRestrictedOrder(AgeRestrictionRule::MINIMUM_AGE);
        $this->orderRepository->shouldReceive('updateFromArray')->andReturn($this->createMockOrder());
        $this->attendeeRepository->shouldReceive('insert')
            ->once()
            ->withArgs(fn(array $inserts) => $inserts[0]['birth_date'] === '1990-03-15')
            ->andReturn(true);
        $this->attendeeRepository->shouldReceive('findWhereIn')->andReturn(new Collection([$this->createMockAttendee()]));
        $this->productQuantityUpdateService->shouldReceive('updateQuantitiesFromOrder');

        $this->completeOrderHandler->handle('ABC123', $this->createMockCompleteOrderDTO('1990-03-15'));

        $this->assertTrue(true);
    }

    public function testBuyerWithoutLastNameIsStoredWithEmptyLastName(): void
    {
        $this->prepareSimpleOrder();
        $this->orderRepository->shouldReceive('updateFromArray')
            ->once()
            ->withArgs(fn($id, array $attributes) => $attributes['last_name'] === '')
            ->andReturn($this->createMockOrder());
        $this->attendeeRepository->shouldReceive('insert')
            ->once()
            ->withArgs(fn(array $inserts) => $inserts[0]['last_name'] === '' && $inserts[0]['first_name'] === 'Jo')
            ->andReturn(true);

        $this->completeOrderHandler->handle('ABC123', new CompleteOrderDTO(
            order: new CompleteOrderOrderDTO(first_name: 'Jo', last_name: '', email: 'jo@example.com', questions: null),
            products: new Collection([new CompleteOrderProductDataDTO(
                product_price_id: 1,
                first_name: 'Jo',
                last_name: null,
                email: 'jo@example.com',
            )]),
            event_id: 1,
        ));

        $this->assertTrue(true);
    }

    public function testMarketingConsentIsTimestampedWhenTicked(): void
    {
        $this->prepareSimpleOrder();
        $this->orderRepository->shouldReceive('updateFromArray')
            ->once()
            ->withArgs(fn($id, array $attributes) => $attributes['opted_into_marketing_at'] instanceof Carbon
                && $attributes['opted_into_marketing_at']->isToday())
            ->andReturn($this->createMockOrder());
        $this->attendeeRepository->shouldReceive('insert')->andReturn(true);

        $this->completeOrderHandler->handle('ABC123', new CompleteOrderDTO(
            order: new CompleteOrderOrderDTO(
                first_name: 'Jo',
                last_name: '',
                email: 'jo@example.com',
                questions: null,
                opted_into_marketing: true,
            ),
            products: $this->createMockCompleteOrderDTO()->products,
            event_id: 1,
        ));

        $this->assertTrue(true);
    }

    public function testMarketingConsentStaysEmptyWhenNotTicked(): void
    {
        $this->prepareSimpleOrder();
        $this->orderRepository->shouldReceive('updateFromArray')
            ->once()
            ->withArgs(fn($id, array $attributes) => array_key_exists('opted_into_marketing_at', $attributes)
                && $attributes['opted_into_marketing_at'] === null)
            ->andReturn($this->createMockOrder());
        $this->attendeeRepository->shouldReceive('insert')->andReturn(true);

        $this->completeOrderHandler->handle('ABC123', $this->createMockCompleteOrderDTO());

        $this->assertTrue(true);
    }

    private function prepareSimpleOrder(): void
    {
        $this->orderRepository->shouldReceive('findByShortId')->with('ABC123')->andReturn($this->createMockOrder());
        $this->orderRepository->shouldReceive('loadRelation')->andReturnSelf();
        $this->productPriceRepository->shouldReceive('findWhereIn')->andReturn(new Collection([$this->createMockProductPrice()]));
        $this->attendeeRepository->shouldReceive('findWhereIn')->andReturn(new Collection([$this->createMockAttendee()]));
        $this->productQuantityUpdateService->shouldReceive('updateQuantitiesFromOrder');
        $this->eventSettingsRepository->shouldReceive('findFirstWhere')->andReturn($this->createMockEventSetting());
    }

    private function prepareAgeRestrictedOrder(AgeRestrictionRule $rule): void
    {
        $this->orderRepository->shouldReceive('findByShortId')->with('ABC123')->andReturn($this->createMockOrder());
        $this->orderRepository->shouldReceive('loadRelation')->andReturnSelf();
        $this->productPriceRepository->shouldReceive('findWhereIn')->andReturn(new Collection([$this->createMockProductPrice()]));
        $this->eventSettingsRepository->shouldReceive('findFirstWhere')->andReturn(
            $this->createMockEventSetting()
                ->setAgeRestrictionRule($rule->value)
                ->setAgeRestrictionMinimumAge(18)
        );
        $this->eventRepository->shouldReceive('findById')->with(1)->andReturn(
            (new EventDomainObject())
                ->setId(1)
                ->setTimezone('Indian/Mayotte')
                ->setStartDate('2026-12-20 16:00:00')
        );
    }

    private function createMockCompleteOrderDTO(?string $birthDate = null): CompleteOrderDTO
    {
        $orderDTO = new CompleteOrderOrderDTO(
            first_name: 'John',
            last_name: 'Doe',
            email: 'john@example.com',
            questions: null,
        );

        $attendeeDTO = new CompleteOrderProductDataDTO(
            product_price_id: 1,
            first_name: 'John',
            last_name: 'Doe',
            email: 'john@example.com',
            birth_date: $birthDate,
        );

        return new CompleteOrderDTO(
            order: $orderDTO,
            products: new Collection([$attendeeDTO])
            , event_id: 1
        );
    }

    private function createMockOrder(OrderStatus $status = OrderStatus::RESERVED): OrderDomainObject|MockInterface
    {
        return (new OrderDomainObject())
            ->setEmail(null)
            ->setSessionId('test-session-id')
            ->setReservedUntil(Carbon::now()->addHour()->toDateTimeString())
            ->setStatus($status->name)
            ->setId(1)
            ->setEventId(1)
            ->setLocale('en')
            ->setTotalGross(10)
            ->setOrderItems(new Collection([
                $this->createMockOrderItem()
            ]));
    }

    private function createMockOrderItem(): OrderItemDomainObject|MockInterface
    {
        return (new OrderItemDomainObject())
            ->setId(1)
            ->setProductId(1)
            ->setQuantity(1)
            ->setPrice(10)
            ->setTotalGross(10)
            ->setProductPriceId(1);
    }

    private function createMockProductPrice(): ProductPriceDomainObject|MockInterface
    {
        $productPrice = Mockery::mock(ProductPriceDomainObject::class);
        $productPrice->shouldReceive('getId')->andReturn(1);
        $productPrice->shouldReceive('getProductId')->andReturn(1);
        return $productPrice;
    }

    private function createMockAttendee(): AttendeeDomainObject|MockInterface
    {
        $attendee = Mockery::mock(AttendeeDomainObject::class);
        $attendee->shouldReceive('getId')->andReturn(1);
        $attendee->shouldReceive('getProductId')->andReturn(1);
        return $attendee;
    }

    private function createMockEventSetting(): EventSettingDomainObject
    {
        return (new EventSettingDomainObject())
            ->setId(1)
            ->setEventId(1);
    }
}
