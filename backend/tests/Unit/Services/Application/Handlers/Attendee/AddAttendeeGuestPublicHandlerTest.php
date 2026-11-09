<?php

namespace Tests\Unit\Services\Application\Handlers\Attendee;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\Status\AttendeeStatus;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Exceptions\AttendeeGuestNotAllowedException;
use HiEvents\Exceptions\InvalidAttendanceCodeException;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Services\Application\Handlers\Attendee\AddAttendeeGuestPublicHandler;
use HiEvents\Services\Application\Handlers\Attendee\DTO\AddAttendeeGuestPublicDTO;
use HiEvents\Services\Domain\Attendee\AttendeeAttendanceService;
use HiEvents\Services\Domain\Attendee\AttendeeGuestService;
use HiEvents\Services\Domain\Attendee\DTO\AttendeeAttendanceContextDTO;
use HiEvents\Services\Domain\Attendee\DTO\AttendeeGuestAvailabilityDTO;
use HiEvents\Services\Domain\Attendee\DTO\AttendeeGuestDataDTO;
use HiEvents\Services\Domain\Attendee\SendAttendeeTicketService;
use HiEvents\Services\Infrastructure\DomainEvents\DomainEventDispatcherService;
use HiEvents\Services\Infrastructure\DomainEvents\Enums\DomainEventType;
use HiEvents\Services\Infrastructure\DomainEvents\Events\AttendeeEvent;
use HiEvents\Services\Infrastructure\HtmlPurifier\HtmlPurifierService;
use Illuminate\Database\DatabaseManager;
use Mockery;
use Mockery\MockInterface;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

class AddAttendeeGuestPublicHandlerTest extends TestCase
{
    private MockInterface|AttendeeAttendanceService $attendanceService;
    private MockInterface|AttendeeGuestService $guestService;
    private MockInterface|SendAttendeeTicketService $sendTicketService;
    private MockInterface|EventRepositoryInterface $eventRepository;
    private MockInterface|OrderRepositoryInterface $orderRepository;
    private MockInterface|DomainEventDispatcherService $domainEventDispatcher;
    private MockInterface|HtmlPurifierService $purifier;
    private MockInterface|DatabaseManager $databaseManager;
    private AddAttendeeGuestPublicHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.customer_locale' => 'fr']);

        $this->attendanceService = Mockery::mock(AttendeeAttendanceService::class);
        $this->guestService = Mockery::mock(AttendeeGuestService::class);
        $this->sendTicketService = Mockery::mock(SendAttendeeTicketService::class);
        $this->eventRepository = Mockery::mock(EventRepositoryInterface::class);
        $this->orderRepository = Mockery::mock(OrderRepositoryInterface::class);
        $this->domainEventDispatcher = Mockery::mock(DomainEventDispatcherService::class);
        $this->purifier = Mockery::mock(HtmlPurifierService::class);
        $this->databaseManager = Mockery::mock(DatabaseManager::class);

        $this->purifier->shouldReceive('purify')->andReturnUsing(fn(?string $value) => $value);
        $this->databaseManager->shouldReceive('transaction')->andReturnUsing(fn(callable $callback) => $callback());

        $this->handler = new AddAttendeeGuestPublicHandler(
            attendeeAttendanceService: $this->attendanceService,
            attendeeGuestService: $this->guestService,
            sendAttendeeTicketService: $this->sendTicketService,
            eventRepository: $this->eventRepository,
            orderRepository: $this->orderRepository,
            domainEventDispatcherService: $this->domainEventDispatcher,
            purifier: $this->purifier,
            databaseManager: $this->databaseManager,
            logger: Mockery::mock(LoggerInterface::class)->shouldIgnoreMissing(),
        );
    }

    public function testAWrongTokenIsRejectedWithoutCreatingAGuest(): void
    {
        $this->attendanceService->shouldReceive('getContext')
            ->once()
            ->with(2, 'a_short', 'wrong-code')
            ->andThrow(new InvalidAttendanceCodeException('Ce lien n\'est pas valide ou a expiré.'));

        $this->guestService->shouldNotReceive('addGuest');
        $this->sendTicketService->shouldNotReceive('send');

        $this->expectException(InvalidAttendanceCodeException::class);

        $this->handler->handle($this->createDTO(code: 'wrong-code'));
    }

    public function testTheGuestIsCreatedAndTheTicketIsSentToTheBuyer(): void
    {
        $context = $this->createContext();
        $guest = (new AttendeeDomainObject())
            ->setId(6)
            ->setEventId(2)
            ->setOrderId(40)
            ->setEmail('buyer@example.com')
            ->setFirstName('Amina')
            ->setLastName('Said');

        $this->attendanceService->shouldReceive('getContext')->once()->andReturn($context);
        $this->attendanceService->shouldReceive('getBlockingReason')->once()->andReturn(null);

        $this->guestService->shouldReceive('addGuest')
            ->once()
            ->withArgs(fn(AttendeeAttendanceContextDTO $given, AttendeeGuestDataDTO $data) => $given === $context
                && $data->firstName === 'Amina'
                && $data->lastName === 'Said'
                && $data->phone === '0639000000')
            ->andReturn($guest);
        $this->guestService->shouldReceive('getAvailability')
            ->once()
            ->andReturn(new AttendeeGuestAvailabilityDTO(canAddGuest: false, placesRemaining: 0, reason: 'Complet'));

        $this->domainEventDispatcher->shouldReceive('dispatch')
            ->once()
            ->withArgs(fn(AttendeeEvent $event) => $event->type === DomainEventType::ATTENDEE_CREATED && $event->attendeeId === 6);

        $event = (new EventDomainObject())->setId(2);
        $event->setEventSettings(new EventSettingDomainObject());
        $event->setOrganizer(new OrganizerDomainObject());
        $this->eventRepository->shouldReceive('loadRelation')->andReturnSelf();
        $this->eventRepository->shouldReceive('findById')->once()->with(2)->andReturn($event);

        $order = $context->order;
        $this->orderRepository->shouldReceive('loadRelation')->andReturnSelf();
        $this->orderRepository->shouldReceive('findById')->once()->with(40)->andReturn($order);

        $this->sendTicketService->shouldReceive('send')
            ->once()
            ->withArgs(fn($givenOrder, $givenAttendee) => $givenOrder === $order && $givenAttendee === $guest);

        $status = $this->handler->handle($this->createDTO(
            firstName: '  <b>Amina</b> ',
            lastName: 'Said',
            phone: '0639000000',
        ));

        $this->assertSame($context->attendee, $status->attendee);
        $this->assertFalse($status->guestAvailability->canAddGuest);
    }

    public function testAnEmptyNameAfterSanitizingIsRejected(): void
    {
        $this->attendanceService->shouldReceive('getContext')->once()->andReturn($this->createContext());
        $this->guestService->shouldNotReceive('addGuest');

        $this->expectException(AttendeeGuestNotAllowedException::class);

        $this->handler->handle($this->createDTO(firstName: '<script></script>'));
    }

    private function createDTO(
        string  $code = 'good-code',
        string  $firstName = 'Amina',
        string  $lastName = 'Said',
        ?string $phone = null,
    ): AddAttendeeGuestPublicDTO
    {
        return new AddAttendeeGuestPublicDTO(
            eventId: 2,
            attendeeShortId: 'a_short',
            code: $code,
            firstName: $firstName,
            lastName: $lastName,
            phone: $phone,
        );
    }

    private function createContext(): AttendeeAttendanceContextDTO
    {
        return new AttendeeAttendanceContextDTO(
            attendee: (new AttendeeDomainObject())
                ->setId(5)
                ->setEventId(2)
                ->setOrderId(40)
                ->setStatus(AttendeeStatus::ACTIVE->name),
            event: (new EventDomainObject())->setId(2),
            order: (new OrderDomainObject())
                ->setId(40)
                ->setEmail('buyer@example.com')
                ->setStatus(OrderStatus::COMPLETED->name),
            locale: 'fr',
        );
    }
}
