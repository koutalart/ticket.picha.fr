<?php

namespace Tests\Unit\Listeners\Order;

use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Events\OrderStatusChangedEvent;
use HiEvents\Jobs\Meta\SendMetaPurchaseConversionJob;
use HiEvents\Listeners\Order\SendMetaPurchaseConversionListener;
use Illuminate\Support\Facades\Bus;
use Psr\Log\NullLogger;
use Tests\TestCase;

class SendMetaPurchaseConversionListenerTest extends TestCase
{
    public function test_queues_completed_online_orders_whatever_the_consent(): void
    {
        Bus::fake();

        (new SendMetaPurchaseConversionListener(new NullLogger))->handle(new OrderStatusChangedEvent(
            $this->order()->setTrackingContext(null),
        ));

        Bus::assertDispatched(SendMetaPurchaseConversionJob::class, fn (SendMetaPurchaseConversionJob $job) => $job->orderId === 77);
    }

    public function test_ignores_box_office_and_unpaid_orders(): void
    {
        Bus::fake();
        $listener = new SendMetaPurchaseConversionListener(new NullLogger);

        $listener->handle(new OrderStatusChangedEvent($this->order()->setIsManuallyCreated(true)));
        $listener->handle(new OrderStatusChangedEvent($this->order()->setStatus(OrderStatus::AWAITING_OFFLINE_PAYMENT->name)));

        Bus::assertNotDispatched(SendMetaPurchaseConversionJob::class);
    }

    private function order(): OrderDomainObject
    {
        return (new OrderDomainObject)
            ->setId(77)
            ->setEventId(3)
            ->setStatus(OrderStatus::COMPLETED->name)
            ->setIsManuallyCreated(false);
    }
}
