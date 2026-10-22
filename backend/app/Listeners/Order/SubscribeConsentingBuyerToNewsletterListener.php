<?php

declare(strict_types=1);

namespace HiEvents\Listeners\Order;

use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Events\OrderStatusChangedEvent;
use HiEvents\Jobs\Organizer\SubscribeConsentingBuyerToNewsletterJob;
use Psr\Log\LoggerInterface;
use Throwable;

class SubscribeConsentingBuyerToNewsletterListener
{
    public function __construct(private readonly LoggerInterface $logger) {}

    public function handle(OrderStatusChangedEvent $changedEvent): void
    {
        $order = $changedEvent->order;

        if ($order->getOptedIntoMarketingAt() === null
            || ! in_array($order->getStatus(), [OrderStatus::COMPLETED->name, OrderStatus::AWAITING_OFFLINE_PAYMENT->name], true)) {
            return;
        }

        try {
            dispatch(new SubscribeConsentingBuyerToNewsletterJob($order->getId()))->afterCommit();
        } catch (Throwable $exception) {
            $this->logger->error('Newsletter subscription from order consent could not be queued', [
                'order_id' => $order->getId(),
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
