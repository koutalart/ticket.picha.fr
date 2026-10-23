<?php

namespace Tests\Unit\Services\Domain\Attendee;

use Carbon\Carbon;
use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\Status\AttendeeStatus;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Exceptions\AttendanceResponseNotAllowedException;
use HiEvents\Exceptions\InvalidAttendanceCodeException;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Services\Domain\Attendee\AttendeeAttendanceLinkGenerator;
use HiEvents\Services\Domain\Attendee\AttendeeAttendanceService;
use HiEvents\Services\Domain\Attendee\AttendeeCancellationService;
use HiEvents\Services\Domain\Attendee\DTO\AttendeeAttendanceContextDTO;
use HiEvents\Services\Domain\Customer\CustomerLocaleResolver;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class AttendeeAttendanceServiceTest extends TestCase
{
    private MockInterface|AttendeeRepositoryInterface $attendeeRepository;
    private MockInterface|EventRepositoryInterface $eventRepository;
    private MockInterface|OrderRepositoryInterface $orderRepository;
    private MockInterface|AttendeeCancellationService $cancellationService;
    private MockInterface|CustomerLocaleResolver $customerLocaleResolver;
    private AttendeeAttendanceLinkGenerator $linkGenerator;
    private AttendeeAttendanceService $service;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:dGVzdC1rZXktZm9yLWF0dGVuZGFuY2UtbGlua3M=']);
        config(['app.customer_locale' => 'fr']);
        Carbon::setTestNow('2026-10-22 10:00:00');

        $this->attendeeRepository = Mockery::mock(AttendeeRepositoryInterface::class);
        $this->eventRepository = Mockery::mock(EventRepositoryInterface::class);
        $this->orderRepository = Mockery::mock(OrderRepositoryInterface::class);
        $this->cancellationService = Mockery::mock(AttendeeCancellationService::class);
        $this->linkGenerator = new AttendeeAttendanceLinkGenerator();
        $this->customerLocaleResolver = Mockery::mock(CustomerLocaleResolver::class);
        $this->customerLocaleResolver->shouldReceive('forEvent')->andReturn('fr')->byDefault();
        $this->customerLocaleResolver->shouldReceive('forEventId')->andReturn('fr')->byDefault();

        $this->service = new AttendeeAttendanceService(
            attendeeRepository: $this->attendeeRepository,
            eventRepository: $this->eventRepository,
            orderRepository: $this->orderRepository,
            attendeeCancellationService: $this->cancellationService,
            linkGenerator: $this->linkGenerator,
            customerLocaleResolver: $this->customerLocaleResolver,
        );
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function testGetContextRejectsAWrongCode(): void
    {
        $this->expectAttendeeLookup($this->createAttendee());

        $this->expectException(InvalidAttendanceCodeException::class);

        $this->service->getContext(2, 'a_short', 'wrong-code');
    }

    public function testGetContextRejectsAnUnknownAttendee(): void
    {
        $this->expectAttendeeLookup(null);

        $this->expectException(InvalidAttendanceCodeException::class);

        $this->service->getContext(2, 'a_short', 'anything');
    }

    public function testGetContextRejectsWhenTheSettingIsDisabled(): void
    {
        $attendee = $this->createAttendee();
        $this->expectAttendeeLookup($attendee);
        $this->expectEventLookup($this->createEvent(enabled: false));

        $this->expectException(AttendanceResponseNotAllowedException::class);

        $this->service->getContext(2, 'a_short', $this->linkGenerator->generateCode($attendee));
    }

    public function testGetContextReturnsTheAttendeeEventAndOrder(): void
    {
        $attendee = $this->createAttendee();
        $this->expectAttendeeLookup($attendee);
        $this->expectEventLookup($this->createEvent());
        $this->orderRepository->shouldReceive('findFirstWhere')
            ->once()
            ->with(['id' => 40, 'event_id' => 2])
            ->andReturn($this->createOrder());

        $context = $this->service->getContext(2, 'a_short', $this->linkGenerator->generateCode($attendee));

        $this->assertSame($attendee, $context->attendee);
        $this->assertSame(40, $context->order->getId());
        $this->assertNull($this->service->getBlockingReason($context));
    }

    public function testConfirmStoresTheConfirmationDate(): void
    {
        $context = $this->createContext();

        $this->attendeeRepository->shouldReceive('updateWhere')
            ->once()
            ->with(
                ['attendance_confirmed_at' => '2026-10-22 10:00:00'],
                ['id' => 5, 'status' => AttendeeStatus::ACTIVE->name],
            )
            ->andReturn(1);
        $this->attendeeRepository->shouldReceive('findById')->once()->with(5)->andReturn($context->attendee);

        $this->service->confirm($context);
    }

    public function testConfirmTwiceKeepsTheFirstDate(): void
    {
        $context = $this->createContext($this->createAttendee()->setAttendanceConfirmedAt('2026-10-20 08:00:00'));

        $this->attendeeRepository->shouldNotReceive('updateWhere');
        $this->attendeeRepository->shouldReceive('findById')->once()->andReturn($context->attendee);

        $this->service->confirm($context);
    }

    public function testCancelUsesTheSharedCancellationService(): void
    {
        $context = $this->createContext();

        $this->cancellationService->shouldReceive('cancel')
            ->once()
            ->with($context->attendee, [
                'cancelled_by_attendee_at' => '2026-10-22 10:00:00',
                'attendance_confirmed_at' => null,
            ])
            ->andReturn(true);
        $this->attendeeRepository->shouldReceive('findById')->once()->with(5)->andReturn($context->attendee);

        $this->service->cancel($context);
    }

    public function testCancelFailsWhenAnotherRequestAlreadyCancelled(): void
    {
        $context = $this->createContext();

        $this->cancellationService->shouldReceive('cancel')->once()->andReturn(false);

        $this->expectException(AttendanceResponseNotAllowedException::class);

        $this->service->cancel($context);
    }

    public function testCannotRespondOnceCancelled(): void
    {
        $context = $this->createContext($this->createAttendee()->setStatus(AttendeeStatus::CANCELLED->name));

        $this->cancellationService->shouldNotReceive('cancel');

        $this->assertSame('Cette inscription a déjà été annulée.', $this->service->getBlockingReason($context));
        $this->expectException(AttendanceResponseNotAllowedException::class);

        $this->service->cancel($context);
    }

    public function testMessagesFollowTheToneOfTheEventOrganizer(): void
    {
        $this->customerLocaleResolver->shouldReceive('forEvent')->andReturn('en');
        $context = $this->createContext($this->createAttendee()->setStatus(AttendeeStatus::CANCELLED->name));

        $this->assertSame('This registration has already been cancelled.', $this->service->getBlockingReason($context));
    }

    public function testAWrongLinkFollowsTheToneOfTheEventOrganizer(): void
    {
        $this->expectAttendeeLookup(null);
        $this->customerLocaleResolver->shouldReceive('forEventId')->once()->with(2)->andReturn('en');

        $this->expectExceptionMessage('This link is invalid or has expired.');

        $this->service->getContext(2, 'a_short', 'anything');
    }

    public function testCannotRespondOnceTheEventHasStarted(): void
    {
        $context = $this->createContext(event: $this->createEvent(startDate: '2026-10-22 09:00:00'));

        $this->attendeeRepository->shouldNotReceive('updateWhere');

        $this->expectException(AttendanceResponseNotAllowedException::class);

        $this->service->confirm($context);
    }

    public function testCannotRespondWhenTheOrderIsNotCompleted(): void
    {
        $context = new AttendeeAttendanceContextDTO(
            attendee: $this->createAttendee(),
            event: $this->createEvent(),
            order: $this->createOrder()->setStatus(OrderStatus::RESERVED->name),
        );

        $this->assertNotNull($this->service->getBlockingReason($context));
    }

    private function createContext(?AttendeeDomainObject $attendee = null, ?EventDomainObject $event = null): AttendeeAttendanceContextDTO
    {
        return new AttendeeAttendanceContextDTO(
            attendee: $attendee ?? $this->createAttendee(),
            event: $event ?? $this->createEvent(),
            order: $this->createOrder(),
        );
    }

    private function expectAttendeeLookup(?AttendeeDomainObject $attendee): void
    {
        $this->attendeeRepository->shouldReceive('loadRelation')->andReturnSelf();
        $this->attendeeRepository->shouldReceive('findFirstWhere')
            ->once()
            ->with(['event_id' => 2, 'short_id' => 'a_short'])
            ->andReturn($attendee);
    }

    private function expectEventLookup(EventDomainObject $event): void
    {
        $this->eventRepository->shouldReceive('loadRelation')->andReturnSelf();
        $this->eventRepository->shouldReceive('findById')->once()->with(2)->andReturn($event);
    }

    private function createAttendee(): AttendeeDomainObject
    {
        return (new AttendeeDomainObject())
            ->setId(5)
            ->setEventId(2)
            ->setOrderId(40)
            ->setProductId(20)
            ->setProductPriceId(30)
            ->setShortId('a_short')
            ->setStatus(AttendeeStatus::ACTIVE->name);
    }

    private function createEvent(bool $enabled = true, string $startDate = '2026-11-15 18:00:00'): EventDomainObject
    {
        $event = (new EventDomainObject())
            ->setId(2)
            ->setTimezone('Indian/Mayotte')
            ->setStartDate($startDate);

        $event->setEventSettings((new EventSettingDomainObject())->setAllowAttendeeAttendanceResponse($enabled));

        return $event;
    }

    private function createOrder(): OrderDomainObject
    {
        return (new OrderDomainObject())
            ->setId(40)
            ->setStatus(OrderStatus::COMPLETED->name);
    }
}
