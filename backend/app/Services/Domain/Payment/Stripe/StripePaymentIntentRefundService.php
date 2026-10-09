<?php

namespace HiEvents\Services\Domain\Payment\Stripe;

use Brick\Math\Exception\MathException;
use HiEvents\DomainObjects\StripePaymentDomainObject;
use HiEvents\Values\MoneyValue;
use Illuminate\Config\Repository;
use RuntimeException;
use Stripe\Exception\ApiErrorException;
use Stripe\Refund;
use Stripe\StripeClient;

class StripePaymentIntentRefundService
{
    public function __construct(
        private readonly Repository $config,
    ) {}

    /**
     * @throws ApiErrorException
     * @throws MathException
     *
     * @todo - catch and handle stripe errors
     */
    /** Stripe refund metadata key set on every refund PICHA initiates itself. */
    public const REFUND_SOURCE_KEY = 'picha_source';

    public const REFUND_SOURCE_DASHBOARD = 'dashboard';

    public const REFUND_SOURCE_EXPIRED_ORDER = 'expired_order';

    public function refundPayment(
        MoneyValue $amount,
        StripePaymentDomainObject $payment,
        StripeClient $stripeClient,
        array $metadata = [],
    ): Refund {
        $params = [
            'payment_intent' => $payment->getPaymentIntentId(),
            'amount' => $amount->toMinorUnit(),
        ];
        if ($metadata !== []) {
            $params['metadata'] = $metadata;
        }

        return $stripeClient->refunds->create(
            params: $params,
            opts: $this->getStripeAccountData($payment),
        );
    }

    private function getStripeAccountData(StripePaymentDomainObject $payment): array
    {
        if ($this->config->get('app.saas_mode_enabled')) {
            if ($payment->getConnectedAccountId() === null) {
                throw new RuntimeException(
                    __('Cannot Refund: Stripe connect account not found and saas_mode_enabled is enabled')
                );
            }

            return [
                'stripe_account' => $payment->getConnectedAccountId(),
            ];
        }

        return [];
    }
}
