<?php

namespace HiEvents\Listeners\Event;

use HiEvents\DomainObjects\Status\OrderPaymentStatus;
use HiEvents\Events\OrderStatusChangedEvent;
use HiEvents\Jobs\Event\UpdateEventStatisticsJob;

class UpdateEventStatsListener
{
    public function handle(OrderStatusChangedEvent $changedEvent): void
    {
        // Orders awaiting an offline payment stay out of the sales until the payment is confirmed (MarkOrderAsPaidService)
        if (!$changedEvent->order->isOrderCompleted()
            || $changedEvent->order->getPaymentStatus() === OrderPaymentStatus::AWAITING_OFFLINE_PAYMENT->name) {
            return;
        }

        dispatch(new UpdateEventStatisticsJob($changedEvent->order));
    }
}
