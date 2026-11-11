<?php

namespace Tests\Unit\Services\Application\Handlers\Attendee\Booth;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\Status\AttendeeStatus;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Services\Application\Handlers\Attendee\Booth\GetBoothAttendeeContactHandler;
use Mockery as m;
use Tests\TestCase;

class GetBoothAttendeeContactHandlerTest extends TestCase
{
    private AttendeeRepositoryInterface&m\MockInterface $attendeeRepository;

    private OrderRepositoryInterface&m\MockInterface $orderRepository;

    private GetBoothAttendeeContactHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->attendeeRepository = m::mock(AttendeeRepositoryInterface::class);
        $this->orderRepository = m::mock(OrderRepositoryInterface::class);
        $this->handler = new GetBoothAttendeeContactHandler($this->attendeeRepository, $this->orderRepository);
    }

    public function test_returns_null_for_unknown_code_or_other_event(): void
    {
        $this->attendeeRepository->shouldReceive('findFirstWhere')
            ->once()
            ->with(['event_id' => 7, 'public_id' => 'A-UNKNOWN'])
            ->andReturnNull();
        $this->orderRepository->shouldNotReceive('findFirstWhere');

        $this->assertNull($this->handler->handle(7, 'A-UNKNOWN'));
    }

    public function test_returns_null_for_cancelled_attendee(): void
    {
        $this->expectAttendee($this->attendee(AttendeeStatus::CANCELLED));
        $this->orderRepository->shouldNotReceive('findFirstWhere');

        $this->assertNull($this->handler->handle(7, 'A-7XK2P9Q'));
    }

    public function test_returns_null_for_attendee_awaiting_payment(): void
    {
        $this->expectAttendee($this->attendee(AttendeeStatus::AWAITING_PAYMENT));
        $this->orderRepository->shouldNotReceive('findFirstWhere');

        $this->assertNull($this->handler->handle(7, 'A-7XK2P9Q'));
    }

    public function test_prefers_attendee_contact_over_order_contact(): void
    {
        $this->expectAttendee($this->attendee(AttendeeStatus::ACTIVE, '+262639111111', 'invite@example.com'));
        $this->expectOrder('+262639222222', 'acheteur@example.com');

        $contact = $this->handler->handle(7, 'A-7XK2P9Q');

        $this->assertSame(
            ['status' => 'ACTIVE', 'phone' => '+262639111111', 'email' => 'invite@example.com'],
            $contact->toArray(),
        );
    }

    public function test_falls_back_to_buyer_contact_field_by_field(): void
    {
        $this->expectAttendee($this->attendee(AttendeeStatus::ACTIVE, '  ', 'invite@example.com'));
        $this->expectOrder('+262639222222', 'acheteur@example.com');

        $contact = $this->handler->handle(7, 'A-7XK2P9Q');

        $this->assertSame('+262639222222', $contact->phone);
        $this->assertSame('invite@example.com', $contact->email);
    }

    public function test_returns_null_fields_when_no_contact_is_known(): void
    {
        $this->expectAttendee($this->attendee(AttendeeStatus::ACTIVE));
        $this->orderRepository->shouldReceive('findFirstWhere')->once()->andReturnNull();

        $contact = $this->handler->handle(7, 'A-7XK2P9Q');

        $this->assertSame(['status' => 'ACTIVE', 'phone' => null, 'email' => null], $contact->toArray());
    }

    private function attendee(AttendeeStatus $status, ?string $phone = null, ?string $email = null): AttendeeDomainObject
    {
        return (new AttendeeDomainObject)
            ->setId(1)
            ->setEventId(7)
            ->setOrderId(42)
            ->setPublicId('A-7XK2P9Q')
            ->setFirstName('Ne')
            ->setLastName('Jamais')
            ->setStatus($status->name)
            ->setPhone($phone)
            ->setEmail($email ?? '')
            ->setCheckedInAt(null);
    }

    private function expectAttendee(AttendeeDomainObject $attendee): void
    {
        $this->attendeeRepository->shouldReceive('findFirstWhere')
            ->once()
            ->with(['event_id' => 7, 'public_id' => 'A-7XK2P9Q'])
            ->andReturn($attendee);
    }

    private function expectOrder(?string $phone, ?string $email): void
    {
        $this->orderRepository->shouldReceive('findFirstWhere')
            ->once()
            ->with(['id' => 42, 'event_id' => 7])
            ->andReturn((new OrderDomainObject)->setId(42)->setEventId(7)->setPhone($phone)->setEmail($email));
    }
}
