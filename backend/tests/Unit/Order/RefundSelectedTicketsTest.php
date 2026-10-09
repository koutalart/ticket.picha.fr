<?php

declare(strict_types=1);

namespace Tests\Unit\Order;

use HiEvents\Exceptions\RefundNotPossibleException;
use HiEvents\Helper\IdHelper;
use HiEvents\Models\Attendee;
use HiEvents\Models\Order;
use HiEvents\Services\Application\Handlers\Order\DTO\RefundOrderDTO;
use HiEvents\Services\Application\Handlers\Order\Payment\Stripe\RefundOrderHandler;
use HiEvents\Services\Domain\Payment\Stripe\EventHandlers\ChargeRefundUpdatedHandler;
use HiEvents\Services\Domain\Payment\Stripe\StripePaymentIntentRefundService;
use HiEvents\Services\Infrastructure\Stripe\StripeClientFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Stripe\Refund;
use Stripe\StripeClient;
use Tests\Support\BoxOfficeTestFixtures;
use Tests\TestCase;

class RefundSelectedTicketsTest extends TestCase
{
    use BoxOfficeTestFixtures;
    use DatabaseTransactions;

    private array $refundCalls = [];

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $refundService = Mockery::mock(StripePaymentIntentRefundService::class);
        $refundService->shouldReceive('refundPayment')->andReturnUsing(function (...$args) {
            $this->refundCalls[] = $args;

            return Refund::constructFrom(['id' => 're_test']);
        });
        $this->app->instance(StripePaymentIntentRefundService::class, $refundService);

        $clientFactory = Mockery::mock(StripeClientFactory::class);
        $clientFactory->shouldReceive('createForPlatform')->andReturn(Mockery::mock(StripeClient::class));
        $this->app->instance(StripeClientFactory::class, $clientFactory);
    }

    public function test_only_the_selected_ticket_is_cancelled(): void
    {
        [$order, [$first, $second], $productPriceId] = $this->paidOrderWithTwoTickets();

        $this->refund($order, 25.00, [$first->id]);

        self::assertSame('CANCELLED', Attendee::find($first->id)->status);
        self::assertSame('ACTIVE', Attendee::find($second->id)->status);
        self::assertSame(1, DB::table('product_prices')->where('id', $productPriceId)->value('quantity_sold'));
    }

    public function test_dashboard_refund_is_tagged_for_the_stripe_webhook(): void
    {
        [$order, [$first]] = $this->paidOrderWithTwoTickets();

        $this->refund($order, 25.00, [$first->id]);

        self::assertCount(1, $this->refundCalls);
        self::assertSame(
            ['picha_source' => 'dashboard', 'picha_attendee_ids' => (string) $first->id],
            $this->refundCalls[0][3] ?? $this->refundCalls[0]['metadata'] ?? null,
        );
    }

    public function test_ticket_of_another_order_is_refused_and_nothing_happens(): void
    {
        [$order] = $this->paidOrderWithTwoTickets();
        [, [$foreign]] = $this->paidOrderWithTwoTickets();

        try {
            $this->refund($order, 25.00, [$foreign->id]);
            self::fail('expected RefundNotPossibleException');
        } catch (RefundNotPossibleException) {
        }

        self::assertSame('ACTIVE', Attendee::find($foreign->id)->status);
        self::assertSame([], $this->refundCalls);
    }

    public function test_refund_made_directly_in_stripe_cancels_the_whole_order(): void
    {
        [$order, [$first, $second]] = $this->paidOrderWithTwoTickets();

        app(ChargeRefundUpdatedHandler::class)->handleEvent($this->stripeRefund($order, 1000, []));

        self::assertSame('CANCELLED', Attendee::find($first->id)->status);
        self::assertSame('CANCELLED', Attendee::find($second->id)->status);
        self::assertSame('CANCELLED', Order::find($order->id)->status);
    }

    public function test_dashboard_refund_webhook_does_not_cancel_other_tickets(): void
    {
        [$order, [$first, $second]] = $this->paidOrderWithTwoTickets();

        app(ChargeRefundUpdatedHandler::class)->handleEvent(
            $this->stripeRefund($order, 2500, ['picha_source' => 'dashboard', 'picha_attendee_ids' => (string) $first->id])
        );

        self::assertSame('ACTIVE', Attendee::find($second->id)->status);
        self::assertSame('COMPLETED', Order::find($order->id)->status);
    }

    private function refund(Order $order, float $amount, array $attendeeIds): void
    {
        app(RefundOrderHandler::class)->handle(new RefundOrderDTO(
            event_id: $order->event_id,
            order_id: $order->id,
            amount: $amount,
            notify_buyer: false,
            cancel_order: false,
            attendee_ids: $attendeeIds,
        ));
    }

    private function stripeRefund(Order $order, int $amountMinor, array $metadata): Refund
    {
        return Refund::constructFrom([
            'id' => 're_'.uniqid(),
            'payment_intent' => 'pi_'.$order->id,
            'amount' => $amountMinor,
            'status' => 'succeeded',
            'metadata' => $metadata,
        ]);
    }

    /**
     * @return array{0: Order, 1: list<Attendee>, 2: int}
     */
    private function paidOrderWithTwoTickets(): array
    {
        [$event, $product, $productPrice] = $this->createEventWithProduct(price: 25.00, quantitySold: 2);

        $order = Order::create([
            'event_id' => $event->id,
            'total_gross' => 50.00,
            'total_refunded' => 0,
            'currency' => 'EUR',
            'status' => 'COMPLETED',
            'payment_status' => 'PAYMENT_RECEIVED',
            'email' => 'acheteur@example.test',
            'first_name' => 'Anli',
            'last_name' => 'Madi',
            'short_id' => IdHelper::shortId(IdHelper::ORDER_PREFIX),
            'public_id' => IdHelper::publicId(IdHelper::ORDER_PREFIX),
        ]);

        DB::table('event_statistics')->updateOrInsert(['event_id' => $event->id], [
            'sales_total_gross' => 50, 'products_sold' => 2, 'orders_created' => 1,
            'attendees_registered' => 2, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('event_daily_statistics')->insert([
            'event_id' => $event->id, 'date' => $order->created_at->toDateString(),
            'sales_total_gross' => 50, 'products_sold' => 2, 'orders_created' => 1,
            'attendees_registered' => 2, 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('stripe_payments')->insert([
            'order_id' => $order->id,
            'payment_intent_id' => 'pi_'.$order->id,
            'connected_account_id' => 'acct_test',
            'application_fee_gross' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $attendees = [];
        foreach (['Anli', 'Madi'] as $name) {
            $attendees[] = Attendee::create([
                'event_id' => $event->id,
                'product_id' => $product->id,
                'product_price_id' => $productPrice->id,
                'order_id' => $order->id,
                'status' => 'ACTIVE',
                'email' => 'acheteur@example.test',
                'first_name' => $name,
                'last_name' => 'Test',
                'short_id' => IdHelper::shortId(IdHelper::ATTENDEE_PREFIX),
                'public_id' => IdHelper::publicId(IdHelper::ATTENDEE_PREFIX),
            ]);
        }

        return [$order, $attendees, $productPrice->id];
    }
}
