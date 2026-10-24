<?php

declare(strict_types=1);

namespace Tests\Unit\LiveChat;

use HiEvents\DomainObjects\StripePaymentDomainObject;
use HiEvents\Services\Domain\Payment\Stripe\StripePaymentIntentRefundService;
use HiEvents\Values\MoneyValue;
use Illuminate\Config\Repository;
use Mockery;
use Stripe\Refund;
use Stripe\Service\RefundService;
use Stripe\StripeClient;
use Tests\TestCase;

/**
 * A refund on a connected account (Stripe Connect, direct charge) is made on that account and never asks Stripe
 * to give back the application fee: the platform fee paid by the buyer stays with the platform.
 */
class StripeRefundKeepsPlatformFeeTest extends TestCase
{
    public function test_refund_is_made_on_the_connected_account_without_refunding_the_application_fee(): void
    {
        $refunds = Mockery::mock(RefundService::class);
        $refunds->shouldReceive('create')
            ->once()
            ->withArgs(function (array $params, array $opts) {
                return $params === [
                    'payment_intent' => 'pi_123',
                    'amount' => 1500,
                    'metadata' => ['picha_source' => 'live_chat'],
                ] && $opts === ['stripe_account' => 'acct_456'];
            })
            ->andReturn(Refund::constructFrom(['id' => 're_1']));

        $client = new class('sk_test_dummy') extends StripeClient
        {
            public ?RefundService $refundsMock = null;

            public function __get($name)
            {
                return $name === 'refunds' ? $this->refundsMock : parent::__get($name);
            }
        };
        $client->refundsMock = $refunds;

        $service = new StripePaymentIntentRefundService(new Repository(['app' => ['saas_mode_enabled' => true]]));
        $service->refundPayment(
            amount: MoneyValue::fromFloat(15.00, 'EUR'),
            payment: (new StripePaymentDomainObject)->setPaymentIntentId('pi_123')->setConnectedAccountId('acct_456'),
            stripeClient: $client,
            metadata: [StripePaymentIntentRefundService::REFUND_SOURCE_KEY => StripePaymentIntentRefundService::REFUND_SOURCE_LIVE_CHAT],
        );
    }
}
