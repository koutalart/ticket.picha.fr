<?php

namespace HiEvents\Listeners\Order;

use HiEvents\Events\OrderStatusChangedEvent;
use HiEvents\Jobs\Sms\SendSmsRegistrationConfirmationJob;
use HiEvents\Services\Domain\Sms\RegistrationConfirmation\SmsRegistrationConfirmationService;
use Psr\Log\LoggerInterface;
use Throwable;

class SendSmsRegistrationConfirmationListener
{
    public function __construct(
        private readonly SmsRegistrationConfirmationService $service,
        private readonly LoggerInterface $logger,
    ) {}

    public function handle(OrderStatusChangedEvent $changedEvent): void
    {
        $order = $changedEvent->order;

        if (! SmsRegistrationConfirmationService::isEligibleOrder($order)) {
            return;
        }

        try {
            if (! $this->service->isEnabledForEvent($order->getEventId())) {
                return;
            }

            dispatch(new SendSmsRegistrationConfirmationJob($order->getId()))->afterCommit();
        } catch (Throwable $exception) {
            $this->logger->error('SMS registration confirmation could not be queued', [
                'order_id' => $order->getId(),
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
