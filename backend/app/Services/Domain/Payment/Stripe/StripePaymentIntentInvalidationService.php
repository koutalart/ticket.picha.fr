<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Payment\Stripe;

use HiEvents\DomainObjects\Enums\StripePlatform;
use HiEvents\DomainObjects\StripePaymentDomainObject;
use HiEvents\Exceptions\PaymentAlreadyStartedException;
use HiEvents\Exceptions\Stripe\StripeClientConfigurationException;
use HiEvents\Repository\Interfaces\StripePaymentsRepositoryInterface;
use HiEvents\Services\Infrastructure\Stripe\StripeClientFactory;
use Psr\Log\LoggerInterface;
use Stripe\Exception\ApiErrorException;
use Stripe\PaymentIntent;

/**
 * When the amount of a reserved order changes, its PaymentIntent is cancelled and detached from the order,
 * so the payment page creates a new one with the right amount and application fee.
 * A PaymentIntent that is already processing or paid is never touched.
 */
class StripePaymentIntentInvalidationService
{
    private const CANCELABLE_STATUSES = [
        PaymentIntent::STATUS_REQUIRES_PAYMENT_METHOD,
        PaymentIntent::STATUS_REQUIRES_CONFIRMATION,
        PaymentIntent::STATUS_REQUIRES_ACTION,
    ];

    public function __construct(
        private readonly StripeClientFactory $stripeClientFactory,
        private readonly StripePaymentsRepositoryInterface $stripePaymentsRepository,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @throws PaymentAlreadyStartedException
     */
    public function invalidate(StripePaymentDomainObject $stripePayment): void
    {
        $this->cancelPaymentIntent($stripePayment, $stripePayment->getPaymentIntentId());

        $this->stripePaymentsRepository->deleteById($stripePayment->getId());
    }

    /**
     * @throws PaymentAlreadyStartedException
     */
    private function cancelPaymentIntent(StripePaymentDomainObject $stripePayment, string $paymentIntentId): void
    {
        $opts = $stripePayment->getConnectedAccountId()
            ? ['stripe_account' => $stripePayment->getConnectedAccountId()]
            : [];

        try {
            $client = $this->stripeClientFactory->createForPlatform(
                StripePlatform::fromString($stripePayment->getStripePlatform())
            );

            $paymentIntent = $client->paymentIntents->retrieve($paymentIntentId, [], $opts);

            if ($paymentIntent->status === PaymentIntent::STATUS_CANCELED) {
                return;
            }

            if (! in_array($paymentIntent->status, self::CANCELABLE_STATUSES, true)) {
                throw new PaymentAlreadyStartedException(
                    __('Your payment is already being processed, the order can no longer be changed.')
                );
            }

            $client->paymentIntents->cancel($paymentIntentId, [], $opts);
        } catch (ApiErrorException|StripeClientConfigurationException $exception) {
            $this->logger->error('Stripe payment intent cancellation failed', [
                'payment_intent_id' => $paymentIntentId,
                'exception' => $exception->getMessage(),
            ]);

            throw new PaymentAlreadyStartedException(
                __('There was an error communicating with the payment provider. Please try again later.')
            );
        }
    }
}
