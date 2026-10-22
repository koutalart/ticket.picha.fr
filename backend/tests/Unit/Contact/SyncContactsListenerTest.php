<?php

declare(strict_types=1);

namespace Tests\Unit\Contact;

use HiEvents\Jobs\Contact\SyncContactsJob;
use HiEvents\Listeners\Contact\SyncContactsListener;
use HiEvents\Services\Infrastructure\DomainEvents\Enums\DomainEventType;
use HiEvents\Services\Infrastructure\DomainEvents\Events\AttendeeEvent;
use HiEvents\Services\Infrastructure\DomainEvents\Events\CheckinEvent;
use HiEvents\Services\Infrastructure\DomainEvents\Events\OrderEvent;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Tests\TestCase;

class SyncContactsListenerTest extends TestCase
{
    public function test_each_domain_event_queues_the_matching_sync(): void
    {
        Bus::fake();
        $listener = $this->app->make(SyncContactsListener::class);

        $listener->handle(new OrderEvent(DomainEventType::ORDER_CREATED, 10));
        $listener->handle(new AttendeeEvent(DomainEventType::ATTENDEE_UPDATED, 20));
        $listener->handle(new CheckinEvent(DomainEventType::CHECKIN_CREATED, 30));

        Bus::assertDispatched(SyncContactsJob::class, fn (SyncContactsJob $job) => $job->orderId === 10);
        Bus::assertDispatched(SyncContactsJob::class, fn (SyncContactsJob $job) => $job->attendeeId === 20);
        Bus::assertDispatched(SyncContactsJob::class, fn (SyncContactsJob $job) => $job->checkInId === 30);
    }

    public function test_a_failure_is_logged_and_never_reaches_the_checkout(): void
    {
        Bus::shouldReceive('dispatch')->andThrow(new RuntimeException('queue down'));
        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('error')->once();

        (new SyncContactsListener($logger))->handle(new OrderEvent(DomainEventType::ORDER_CREATED, 10));

        $this->addToAssertionCount(1);
    }
}
