<?php

namespace Tests\Unit\Listeners\Attendee;

use HiEvents\Jobs\Sms\SendSmsAttendeeCancellationConfirmationJob;
use HiEvents\Jobs\Sms\SendSmsOrderCancellationConfirmationJob;
use HiEvents\Listeners\Attendee\SendSmsCancellationConfirmationListener;
use HiEvents\Services\Domain\Sms\CancellationConfirmation\SmsCancellationConfirmationService;
use HiEvents\Services\Infrastructure\DomainEvents\Enums\DomainEventType;
use HiEvents\Services\Infrastructure\DomainEvents\Events\AttendeeEvent;
use HiEvents\Services\Infrastructure\DomainEvents\Events\OrderEvent;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Tests\TestCase;

class SendSmsCancellationConfirmationListenerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private SendSmsCancellationConfirmationListener $listener;

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake();
        $this->listener = new SendSmsCancellationConfirmationListener(new NullLogger);
    }

    public function test_queues_the_sms_when_an_attendee_is_cancelled(): void
    {
        $this->listener->handleAttendeeEvent(new AttendeeEvent(DomainEventType::ATTENDEE_CANCELLED, 12));

        Bus::assertDispatched(SendSmsAttendeeCancellationConfirmationJob::class, fn (SendSmsAttendeeCancellationConfirmationJob $job) => $job->attendeeId === 12);
    }

    public function test_queues_the_sms_when_an_order_is_cancelled(): void
    {
        $this->listener->handleOrderEvent(new OrderEvent(DomainEventType::ORDER_CANCELLED, 40));

        Bus::assertDispatched(SendSmsOrderCancellationConfirmationJob::class, fn (SendSmsOrderCancellationConfirmationJob $job) => $job->orderId === 40);
    }

    public function test_other_events_are_ignored(): void
    {
        $this->listener->handleAttendeeEvent(new AttendeeEvent(DomainEventType::ATTENDEE_CREATED, 12));
        $this->listener->handleAttendeeEvent(new AttendeeEvent(DomainEventType::ATTENDEE_UPDATED, 12));
        $this->listener->handleOrderEvent(new OrderEvent(DomainEventType::ORDER_CREATED, 40));
        $this->listener->handleOrderEvent(new OrderEvent(DomainEventType::ORDER_REFUNDED, 40));

        Bus::assertNotDispatched(SendSmsAttendeeCancellationConfirmationJob::class);
        Bus::assertNotDispatched(SendSmsOrderCancellationConfirmationJob::class);
    }

    public function test_the_listener_is_registered_for_both_domain_events(): void
    {
        $listeners = fn (string $event) => array_map(
            fn ($listener) => is_array($listener) ? implode('@', $listener) : (string) $listener,
            app('events')->getRawListeners()[$event] ?? [],
        );

        $this->assertContains(SendSmsCancellationConfirmationListener::class.'@handleAttendeeEvent', $listeners(AttendeeEvent::class));
        $this->assertContains(SendSmsCancellationConfirmationListener::class.'@handleOrderEvent', $listeners(OrderEvent::class));
    }

    public function test_the_jobs_log_errors_without_failing(): void
    {
        $service = Mockery::mock(SmsCancellationConfirmationService::class);
        $service->shouldReceive('sendForAttendee')->with(12)->andThrow(new RuntimeException('Base indisponible'));
        $service->shouldReceive('sendForOrder')->with(40)->andThrow(new RuntimeException('Base indisponible'));
        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('error')->once()->with('SMS cancellation confirmation failed', Mockery::type('array'));
        $logger->shouldReceive('error')->once()->with('SMS cancellation confirmations failed', Mockery::type('array'));

        (new SendSmsAttendeeCancellationConfirmationJob(12))->handle($service, $logger);
        (new SendSmsOrderCancellationConfirmationJob(40))->handle($service, $logger);
    }
}
