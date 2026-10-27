<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Order\Public;

use HiEvents\Exceptions\PaymentAlreadyStartedException;
use HiEvents\Exceptions\RefundProtectionUnavailableException;
use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Exceptions\UnauthorizedException;
use HiEvents\Services\Domain\Order\RefundProtection\DTO\RefundProtectionOfferDTO;
use HiEvents\Services\Domain\Order\RefundProtection\OrderRefundProtectionService;
use HiEvents\Services\Domain\Payment\Stripe\StripePaymentIntentInvalidationService;
use Illuminate\Database\DatabaseManager;
use Psr\Log\LoggerInterface;
use Throwable;

class UpdateOrderRefundProtectionPublicHandler
{
    public function __construct(
        private readonly RefundProtectionOrderLoader $orderLoader,
        private readonly OrderRefundProtectionService $refundProtectionService,
        private readonly StripePaymentIntentInvalidationService $paymentIntentInvalidationService,
        private readonly DatabaseManager $databaseManager,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * The Stripe cancellation runs last inside the transaction: if Stripe refuses it, nothing is changed.
     *
     * @throws RefundProtectionUnavailableException
     * @throws PaymentAlreadyStartedException
     * @throws ResourceConflictException
     * @throws UnauthorizedException
     * @throws Throwable
     */
    public function handle(int $eventId, string $orderShortId, bool $enabled): ?RefundProtectionOfferDTO
    {
        return $this->databaseManager->transaction(function () use ($eventId, $orderShortId, $enabled) {
            $this->databaseManager->statement('SELECT pg_advisory_xact_lock(?)', [$eventId]);

            $order = $this->orderLoader->loadOrder($eventId, $orderShortId);
            $event = $this->orderLoader->loadEvent($eventId);

            $updatedOrder = $this->refundProtectionService->apply($order, $event, $enabled);

            $stripePayment = $order->getStripePayment();
            $totalChanged = round((float) $updatedOrder->getTotalGross(), 2) !== round((float) $order->getTotalGross(), 2);

            if ($stripePayment !== null && $totalChanged) {
                $this->paymentIntentInvalidationService->invalidate($stripePayment);
            }

            $this->logger->info('Order refund protection updated by customer', [
                'order_id' => $order->getId(),
                'event_id' => $eventId,
                'enabled' => $enabled,
                'previous_total_gross' => $order->getTotalGross(),
                'total_gross' => $updatedOrder->getTotalGross(),
                'payment_intent_invalidated' => $stripePayment !== null && $totalChanged,
            ]);

            return $this->refundProtectionService->getOffer($updatedOrder, $event);
        });
    }
}
