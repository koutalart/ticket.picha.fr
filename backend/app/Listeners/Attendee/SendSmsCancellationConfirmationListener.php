<?php

namespace HiEvents\Listeners\Attendee;

use HiEvents\Jobs\Sms\SendSmsAttendeeCancellationConfirmationJob;
use HiEvents\Jobs\Sms\SendSmsOrderCancellationConfirmationJob;
use HiEvents\Services\Infrastructure\DomainEvents\Enums\DomainEventType;
use HiEvents\Services\Infrastructure\DomainEvents\Events\AttendeeEvent;
use HiEvents\Services\Infrastructure\DomainEvents\Events\OrderEvent;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Queues the cancellation SMS once the cancellation is committed. The job checks the event settings and who cancelled.
 */
class SendSmsCancellationConfirmationListener
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function handleAttendeeEvent(AttendeeEvent $event): void
    {
        if ($event->type !== DomainEventType::ATTENDEE_CANCELLED) {
            return;
        }

        $this->queue(fn () => dispatch(new SendSmsAttendeeCancellationConfirmationJob($event->attendeeId))->afterCommit(), [
            'attendee_id' => $event->attendeeId,
        ]);
    }

    public function handleOrderEvent(OrderEvent $event): void
    {
        if ($event->type !== DomainEventType::ORDER_CANCELLED) {
            return;
        }

        $this->queue(fn () => dispatch(new SendSmsOrderCancellationConfirmationJob($event->orderId))->afterCommit(), [
            'order_id' => $event->orderId,
        ]);
    }

    private function queue(callable $dispatch, array $context): void
    {
        try {
            $dispatch();
        } catch (Throwable $exception) {
            $this->logger->error('SMS cancellation confirmation could not be queued', [
                ...$context,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
