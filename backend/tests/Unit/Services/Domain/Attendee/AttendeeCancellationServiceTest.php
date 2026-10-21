<?php

namespace Tests\Unit\Services\Domain\Attendee;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\Enums\CapacityChangeDirection;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\Status\AttendeeStatus;
use HiEvents\Events\CapacityChangedEvent;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Services\Domain\Attendee\AttendeeCancellationService;
use HiEvents\Services\Domain\EventStatistics\EventStatisticsCancellationService;
use HiEvents\Services\Domain\Product\ProductQuantityUpdateService;
use HiEvents\Services\Infrastructure\DomainEvents\DomainEventDispatcherService;
use HiEvents\Services\Infrastructure\DomainEvents\Enums\DomainEventType;
use HiEvents\Services\Infrastructure\DomainEvents\Events\AttendeeEvent;
use Illuminate\Contracts\Events\Dispatcher;
use Mockery;
use Mockery\MockInterface;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

class AttendeeCancellationServiceTest extends TestCase
{
    private MockInterface|AttendeeRepositoryInterface $attendeeRepository;
    private MockInterface|OrderRepositoryInterface $orderRepository;
    private MockInterface|ProductQuantityUpdateService $productQuantityService;
    private MockInterface|EventStatisticsCancellationService $statisticsService;
    private MockInterface|DomainEventDispatcherService $domainEventDispatcher;
    private MockInterface|Dispatcher $eventDispatcher;
    private AttendeeCancellationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->attendeeRepository = Mockery::mock(AttendeeRepositoryInterface::class);
        $this->orderRepository = Mockery::mock(OrderRepositoryInterface::class);
        $this->productQuantityService = Mockery::mock(ProductQuantityUpdateService::class);
        $this->statisticsService = Mockery::mock(EventStatisticsCancellationService::class);
        $this->domainEventDispatcher = Mockery::mock(DomainEventDispatcherService::class);
        $this->eventDispatcher = Mockery::mock(Dispatcher::class);

        $this->service = new AttendeeCancellationService(
            attendeeRepository: $this->attendeeRepository,
            orderRepository: $this->orderRepository,
            productQuantityService: $this->productQuantityService,
            eventStatisticsCancellationService: $this->statisticsService,
            domainEventDispatcherService: $this->domainEventDispatcher,
            eventDispatcher: $this->eventDispatcher,
            logger: Mockery::mock(LoggerInterface::class)->shouldIgnoreMissing(),
        );
    }

    public function testCancelReleasesThePlaceAndNotifiesListeners(): void
    {
        $attendee = $this->createAttendee();

        $this->attendeeRepository->shouldReceive('updateWhere')
            ->once()
            ->with(
                [
                    'cancelled_by_attendee_at' => '2026-10-22 10:00:00',
                    'status' => AttendeeStatus::CANCELLED->name,
                ],
                ['id' => 5, 'status' => AttendeeStatus::ACTIVE->name],
            )
            ->andReturn(1);

        $this->productQuantityService->shouldReceive('decreaseQuantitySold')->once()->with(30);

        $order = (new OrderDomainObject())->setId(40)->setCreatedAt('2026-10-01 09:00:00');
        $this->orderRepository->shouldReceive('findFirstWhere')
            ->once()
            ->with(['id' => 40, 'event_id' => 2])
            ->andReturn($order);

        $this->statisticsService->shouldReceive('decrementForCancelledAttendee')
            ->once()
            ->with(2, '2026-10-01 09:00:00');

        $this->domainEventDispatcher->shouldReceive('dispatch')
            ->once()
            ->with(Mockery::on(fn(AttendeeEvent $event) => $event->type === DomainEventType::ATTENDEE_CANCELLED
                && $event->attendeeId === 5));

        $this->eventDispatcher->shouldReceive('dispatch')
            ->once()
            ->with(Mockery::on(fn(CapacityChangedEvent $event) => $event->eventId === 2
                && $event->direction === CapacityChangeDirection::INCREASED
                && $event->productId === 20
                && $event->productPriceId === 30));

        $this->assertTrue($this->service->cancel($attendee, ['cancelled_by_attendee_at' => '2026-10-22 10:00:00']));
    }

    public function testCancelDoesNothingWhenTheStatusAlreadyChanged(): void
    {
        $attendee = $this->createAttendee();

        $this->attendeeRepository->shouldReceive('updateWhere')->once()->andReturn(0);
        $this->productQuantityService->shouldNotReceive('decreaseQuantitySold');
        $this->statisticsService->shouldNotReceive('decrementForCancelledAttendee');
        $this->domainEventDispatcher->shouldNotReceive('dispatch');
        $this->eventDispatcher->shouldNotReceive('dispatch');

        $this->assertFalse($this->service->cancel($attendee));
    }

    private function createAttendee(): AttendeeDomainObject
    {
        return (new AttendeeDomainObject())
            ->setId(5)
            ->setEventId(2)
            ->setOrderId(40)
            ->setProductId(20)
            ->setProductPriceId(30)
            ->setStatus(AttendeeStatus::ACTIVE->name);
    }
}
