<?php

namespace Tests\Unit\Services\Domain\Attendee\AttendanceAccess;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\Enums\AttendanceAccessChannel;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsCustomerDirectoryRepositoryInterface;
use HiEvents\Services\Domain\Attendee\AttendanceAccess\AttendanceAccessContactMatcher;
use HiEvents\Services\Domain\Attendee\AttendanceAccess\DTO\AttendanceAccessContactDTO;
use HiEvents\Services\Domain\Sms\DTO\SmsReminderAttendeeDTO;
use Mockery as m;
use Tests\TestCase;

class AttendanceAccessContactMatcherTest extends TestCase
{
    private AttendeeRepositoryInterface $attendeeRepository;

    private OrderRepositoryInterface $orderRepository;

    private SmsCustomerDirectoryRepositoryInterface $directoryRepository;

    private AttendanceAccessContactMatcher $matcher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->attendeeRepository = m::mock(AttendeeRepositoryInterface::class);
        $this->orderRepository = m::mock(OrderRepositoryInterface::class);
        $this->directoryRepository = m::mock(SmsCustomerDirectoryRepositoryInterface::class);
        $this->matcher = new AttendanceAccessContactMatcher(
            $this->attendeeRepository,
            $this->orderRepository,
            $this->directoryRepository,
        );
    }

    public function test_email_matches_attendees_and_every_active_attendee_of_the_order(): void
    {
        $this->attendeeRepository->shouldReceive('findWhere')
            ->once()
            ->with(m::on(fn (array $where) => $where['event_id'] === 4
                && $where['status'] === 'ACTIVE'
                && $where[0] === ['email', 'ilike', 'jo@example.com']))
            ->andReturn(collect([$this->attendee(30)]));

        $this->orderRepository->shouldReceive('findWhere')
            ->once()
            ->with(m::on(fn (array $where) => $where['event_id'] === 4
                && $where['status'] === 'COMPLETED'
                && $where[0] === ['email', 'ilike', 'jo@example.com']))
            ->andReturn(collect([(new OrderDomainObject)->setId(7)]));

        $this->attendeeRepository->shouldReceive('findWhereIn')
            ->once()
            ->with('order_id', [7], ['event_id' => 4, 'status' => 'ACTIVE'])
            ->andReturn(collect([$this->attendee(21), $this->attendee(30), $this->attendee(22)]));

        $ids = $this->matcher->findAttendeeIds(4, new AttendanceAccessContactDTO(AttendanceAccessChannel::EMAIL, 'jo@example.com'));

        $this->assertSame([21, 22, 30], $ids);
    }

    public function test_email_wildcards_are_escaped(): void
    {
        $this->attendeeRepository->shouldReceive('findWhere')
            ->once()
            ->with(m::on(fn (array $where) => $where[0] === ['email', 'ilike', 'a\\_b\\%@example.com']))
            ->andReturn(collect());
        $this->orderRepository->shouldReceive('findWhere')->once()->andReturn(collect());
        $this->attendeeRepository->shouldNotReceive('findWhereIn');

        $ids = $this->matcher->findAttendeeIds(4, new AttendanceAccessContactDTO(AttendanceAccessChannel::EMAIL, 'a_b%@example.com'));

        $this->assertSame([], $ids);
    }

    public function test_phone_matches_normalized_numbers_only(): void
    {
        $this->directoryRepository->shouldReceive('findActiveAttendeesWithPhone')
            ->once()
            ->with(4)
            ->andReturn(collect([
                new SmsReminderAttendeeDTO(attendeeId: 40, phone: '0639 00 00 01', firstName: 'Ana', lastName: null),
                new SmsReminderAttendeeDTO(attendeeId: 41, phone: '+262639000001', firstName: 'Ben', lastName: null),
                new SmsReminderAttendeeDTO(attendeeId: 42, phone: '0639 00 00 02', firstName: 'Cy', lastName: null),
                new SmsReminderAttendeeDTO(attendeeId: 43, phone: null, firstName: 'Di', lastName: null),
            ]));

        $ids = $this->matcher->findAttendeeIds(4, new AttendanceAccessContactDTO(AttendanceAccessChannel::PHONE, '+262639000001'));

        $this->assertSame([40, 41], $ids);
    }

    public function test_unknown_phone_matches_nobody(): void
    {
        $this->directoryRepository->shouldReceive('findActiveAttendeesWithPhone')
            ->once()
            ->andReturn(collect([
                new SmsReminderAttendeeDTO(attendeeId: 40, phone: '0639 00 00 01', firstName: 'Ana', lastName: null),
            ]));

        $this->assertSame([], $this->matcher->findAttendeeIds(4, new AttendanceAccessContactDTO(AttendanceAccessChannel::PHONE, '+33612345678')));
    }

    private function attendee(int $id): AttendeeDomainObject
    {
        return (new AttendeeDomainObject)->setId($id);
    }
}
