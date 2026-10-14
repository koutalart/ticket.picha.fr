<?php

namespace HiEvents\Listeners\Order;

use HiEvents\Events\OrderStatusChangedEvent;
use HiEvents\Jobs\Meta\SendMetaPurchaseConversionJob;
use HiEvents\Services\Domain\Meta\MetaPurchaseConversionService;

class SendMetaPurchaseConversionListener
{
    public function handle(OrderStatusChangedEvent $changedEvent): void
    {
        if (! MetaPurchaseConversionService::isEligible($changedEvent->order)) {
            return;
        }

        dispatch(new SendMetaPurchaseConversionJob($changedEvent->order->getId()));
    }
}
