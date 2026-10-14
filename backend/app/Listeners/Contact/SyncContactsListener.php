<?php

namespace HiEvents\Listeners\Contact;

use HiEvents\Jobs\Contact\SyncContactsJob;
use HiEvents\Services\Infrastructure\DomainEvents\Events\AttendeeEvent;
use HiEvents\Services\Infrastructure\DomainEvents\Events\BaseDomainEvent;
use HiEvents\Services\Infrastructure\DomainEvents\Events\CheckinEvent;
use HiEvents\Services\Infrastructure\DomainEvents\Events\OrderEvent;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Never lets an exception escape: the domain event dispatcher rethrows,
 * and a CRM failure must not break a checkout or a scan.
 */
class SyncContactsListener
{
    public function __construct(private readonly LoggerInterface $logger) {}

    public function handle(BaseDomainEvent $event): void
    {
        try {
            $job = match (true) {
                $event instanceof OrderEvent => new SyncContactsJob(orderId: $event->orderId),
                $event instanceof AttendeeEvent => new SyncContactsJob(attendeeId: $event->attendeeId),
                $event instanceof CheckinEvent => new SyncContactsJob(checkInId: $event->attendeeCheckinId),
                default => null,
            };

            if ($job !== null) {
                dispatch($job->afterCommit());
            }
        } catch (Throwable $exception) {
            $this->logger->error('Failed to queue contact sync', [
                'event' => get_class($event),
                'exception' => $exception->getMessage(),
            ]);
        }
    }
}
