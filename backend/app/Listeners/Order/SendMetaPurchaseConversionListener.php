<?php

namespace HiEvents\Listeners\Order;

use HiEvents\Events\OrderStatusChangedEvent;
use HiEvents\Jobs\Meta\SendMetaPurchaseConversionJob;
use HiEvents\Services\Domain\Meta\MetaPurchaseConversionService;
use Psr\Log\LoggerInterface;
use Throwable;

class SendMetaPurchaseConversionListener
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function handle(OrderStatusChangedEvent $changedEvent): void
    {
        if (! MetaPurchaseConversionService::isEligible($changedEvent->order)) {
            return;
        }

        try {
            dispatch(new SendMetaPurchaseConversionJob($changedEvent->order->getId()));
        } catch (Throwable $exception) {
            $this->logger->warning('Meta Conversions API purchase could not be queued', [
                'order_id' => $changedEvent->order->getId(),
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
