<?php

declare(strict_types=1);

namespace Tests\Unit\Listeners;

use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\Events\OrderStatusChangedEvent;
use HiEvents\Jobs\Event\UpdateEventStatisticsJob;
use HiEvents\Listeners\Event\UpdateEventStatsListener;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * Orders awaiting an offline payment stay out of the sales; they count once the payment is confirmed.
 */
class UpdateEventStatsListenerOfflineTest extends TestCase
{
    public function test_completed_order_awaiting_offline_payment_is_not_counted(): void
    {
        Bus::fake();

        (new UpdateEventStatsListener())->handle(new OrderStatusChangedEvent($this->order('AWAITING_OFFLINE_PAYMENT')));

        Bus::assertNotDispatched(UpdateEventStatisticsJob::class);
    }

    public function test_order_counted_once_the_offline_payment_is_confirmed(): void
    {
        Bus::fake();

        (new UpdateEventStatsListener())->handle(new OrderStatusChangedEvent($this->order('PAYMENT_RECEIVED')));

        Bus::assertDispatched(UpdateEventStatisticsJob::class);
    }

    private function order(string $paymentStatus): OrderDomainObject
    {
        return (new OrderDomainObject())
            ->setId(1)
            ->setEventId(1)
            ->setStatus('COMPLETED')
            ->setPaymentStatus($paymentStatus);
    }
}
