<?php

declare(strict_types=1);

namespace Tests\Unit\Order;

use HiEvents\Models\Event;
use HiEvents\Models\Product;
use HiEvents\Models\ProductPrice;
use HiEvents\Models\StripePayment;
use HiEvents\Services\Infrastructure\Stripe\StripeClientFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Mockery;
use Stripe\PaymentIntent;
use Stripe\StripeClient;
use Tests\Support\BoxOfficeTestFixtures;
use Tests\TestCase;

class RefundProtectionCheckoutTest extends TestCase
{
    use BoxOfficeTestFixtures;
    use DatabaseTransactions;

    private const SESSION = 'session-refund-protection-buyer';

    private Event $event;

    private Product $ticket;

    private ProductPrice $ticketPrice;

    private Product $protection;

    private ProductPrice $protectionPrice;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.saas_mode_enabled' => false]);

        [$this->event, $this->ticket, $this->ticketPrice] = $this->createEventWithProduct(price: 15.00);
        DB::table('events')->where('id', $this->event->id)->update([
            'currency' => 'EUR',
            'start_date' => now()->addDays(4),
            'end_date' => now()->addDays(4)->addHours(6),
        ]);

        $this->protection = Product::create([
            'event_id' => $this->event->id,
            'title' => 'Option sérénité',
            'description' => '<p>Remboursement du billet si tu ne peux plus venir.</p>',
            'product_type' => 'GENERAL',
            'type' => 'PAID',
            'order' => 2,
            'is_refund_protection' => true,
        ]);
        $this->protectionPrice = ProductPrice::create([
            'product_id' => $this->protection->id,
            'price' => 0.49,
            'initial_quantity_available' => null,
            'quantity_sold' => 0,
            'order' => 1,
        ]);
    }

    public function test_the_offer_is_computed_by_the_server_and_toggling_updates_the_order(): void
    {
        $orderShortId = $this->createOrder(tickets: 2);
        $this->assertOrderTotal($orderShortId, 30.00);

        $this->getOffer($orderShortId)
            ->assertOk()
            ->assertJsonPath('data.quantity', 1)
            ->assertJsonPath('data.total_before_additions', 0.49)
            ->assertJsonPath('data.is_selected', false)
            ->assertJsonPath('data.is_locked', false)
            ->assertJsonPath('data.description', 'Remboursement du billet si tu ne peux plus venir.');

        $this->setProtection($orderShortId, true)
            ->assertOk()
            ->assertJsonPath('data.is_selected', true)
            ->assertJsonPath('data.total_gross', 0.49);
        $this->assertOrderTotal($orderShortId, 30.49);

        $this->setProtection($orderShortId, true)->assertOk();
        $this->assertOrderTotal($orderShortId, 30.49);
        $this->assertSame(1, $this->protectionLineCount($orderShortId));

        $this->setProtection($orderShortId, false)
            ->assertOk()
            ->assertJsonPath('data.is_selected', false);
        $this->assertOrderTotal($orderShortId, 30.00);
        $this->assertSame(0, $this->protectionLineCount($orderShortId, withTrashed: true));
    }

    public function test_the_protection_is_hidden_from_the_quantity_selection_and_a_quantity_sent_by_an_old_page_is_normalised(): void
    {
        $orderShortId = $this->createOrder(tickets: 2, protectionQuantity: 3);

        $this->assertSame(1, (int) DB::table('order_items')
            ->where('order_id', $this->orderId($orderShortId))
            ->where('product_id', $this->protection->id)
            ->value('quantity'));
        $this->assertOrderTotal($orderShortId, 30.49);
    }

    public function test_per_ticket_mode_follows_the_number_of_tickets(): void
    {
        $this->protection->update(['required_quantity_mode' => 'PER_TICKET']);
        $orderShortId = $this->createOrder(tickets: 3);

        $this->getOffer($orderShortId)
            ->assertJsonPath('data.quantity', 3)
            ->assertJsonPath('data.is_per_ticket', true)
            ->assertJsonPath('data.total_before_additions', 1.47);

        $this->setProtection($orderShortId, true)->assertOk();
        $this->assertOrderTotal($orderShortId, 46.47);
    }

    public function test_a_required_protection_is_added_at_creation_and_cannot_be_removed(): void
    {
        $this->protection->update(['is_required' => true, 'required_quantity_mode' => 'PER_TICKET']);

        $orderShortId = $this->createOrder(tickets: 2);

        $this->assertOrderTotal($orderShortId, 30.98);
        $this->getOffer($orderShortId)
            ->assertJsonPath('data.is_selected', true)
            ->assertJsonPath('data.is_locked', true)
            ->assertJsonPath('data.quantity', 2);

        $this->setProtection($orderShortId, false)->assertStatus(422);
        $this->assertOrderTotal($orderShortId, 30.98);
    }

    public function test_another_session_cannot_change_the_order(): void
    {
        $orderShortId = $this->createOrder(tickets: 1);

        $this->putJson($this->url($orderShortId, 'another-session'), ['enabled' => true])->assertStatus(401);
        $this->assertOrderTotal($orderShortId, 15.00);
    }

    public function test_an_expired_order_cannot_be_changed(): void
    {
        $orderShortId = $this->createOrder(tickets: 1);
        DB::table('orders')->where('short_id', $orderShortId)->update(['reserved_until' => now()->subMinute()]);

        $this->setProtection($orderShortId, true)->assertStatus(409);
    }

    public function test_an_existing_payment_intent_is_cancelled_when_the_total_changes(): void
    {
        $orderShortId = $this->createOrder(tickets: 1);
        $stripePaymentId = $this->attachStripePayment($orderShortId);
        $paymentIntents = $this->fakeStripe(PaymentIntent::STATUS_REQUIRES_PAYMENT_METHOD);
        $paymentIntents->shouldReceive('cancel')->once()->with('pi_test_refund_protection', [], [])->andReturn(
            PaymentIntent::constructFrom(['id' => 'pi_test_refund_protection', 'status' => PaymentIntent::STATUS_CANCELED])
        );

        $this->setProtection($orderShortId, true)->assertOk();

        $this->assertOrderTotal($orderShortId, 15.49);
        $this->assertSoftDeleted('stripe_payments', ['id' => $stripePaymentId]);
    }

    public function test_a_payment_already_processing_blocks_the_change(): void
    {
        $orderShortId = $this->createOrder(tickets: 1);
        $stripePaymentId = $this->attachStripePayment($orderShortId);
        $paymentIntents = $this->fakeStripe(PaymentIntent::STATUS_PROCESSING);
        $paymentIntents->shouldNotReceive('cancel');

        $this->setProtection($orderShortId, true)->assertStatus(409);

        $this->assertOrderTotal($orderShortId, 15.00);
        $this->assertSame(0, $this->protectionLineCount($orderShortId, withTrashed: true));
        $this->assertNotSoftDeleted('stripe_payments', ['id' => $stripePaymentId]);
    }

    public function test_no_offer_without_a_paid_ticket(): void
    {
        $this->ticketPrice->update(['price' => 0]);
        $this->ticket->update(['type' => 'FREE']);

        $orderShortId = $this->createOrder(tickets: 1);

        $this->getOffer($orderShortId)->assertOk()->assertJsonPath('data', null);
        $this->setProtection($orderShortId, true)->assertStatus(422);
    }

    private function createOrder(int $tickets, int $protectionQuantity = 0): string
    {
        $products = [[
            'product_id' => $this->ticket->id,
            'quantities' => [['price_id' => $this->ticketPrice->id, 'quantity' => $tickets]],
        ]];

        if ($protectionQuantity > 0) {
            $products[] = [
                'product_id' => $this->protection->id,
                'quantities' => [['price_id' => $this->protectionPrice->id, 'quantity' => $protectionQuantity]],
            ];
        }

        $response = $this->postJson(
            "/public/events/{$this->event->id}/order?session_identifier=".self::SESSION,
            ['products' => $products],
        );
        $response->assertCreated();

        return $response->json('data.short_id');
    }

    private function url(string $orderShortId, string $session = self::SESSION): string
    {
        return "/public/events/{$this->event->id}/order/{$orderShortId}/refund-protection?session_identifier={$session}";
    }

    private function getOffer(string $orderShortId): TestResponse
    {
        return $this->getJson($this->url($orderShortId));
    }

    private function setProtection(string $orderShortId, bool $enabled): TestResponse
    {
        return $this->putJson($this->url($orderShortId), ['enabled' => $enabled]);
    }

    private function orderId(string $orderShortId): int
    {
        return (int) DB::table('orders')->where('short_id', $orderShortId)->value('id');
    }

    private function assertOrderTotal(string $orderShortId, float $expected): void
    {
        $this->assertEqualsWithDelta($expected, (float) DB::table('orders')->where('short_id', $orderShortId)->value('total_gross'), 0.001);
    }

    private function protectionLineCount(string $orderShortId, bool $withTrashed = false): int
    {
        $query = DB::table('order_items')
            ->where('order_id', $this->orderId($orderShortId))
            ->where('product_id', $this->protection->id);

        if (! $withTrashed) {
            $query->whereNull('deleted_at');
        }

        return $query->count();
    }

    private function attachStripePayment(string $orderShortId): int
    {
        return (int) StripePayment::create([
            'order_id' => $this->orderId($orderShortId),
            'payment_intent_id' => 'pi_test_refund_protection',
            'currency' => 'EUR',
        ])->id;
    }

    private function fakeStripe(string $status): Mockery\MockInterface
    {
        $paymentIntents = Mockery::mock();
        $paymentIntents->shouldReceive('retrieve')->with('pi_test_refund_protection', [], [])->andReturn(
            PaymentIntent::constructFrom(['id' => 'pi_test_refund_protection', 'status' => $status, 'amount' => 1500])
        );

        $client = new class('sk_test_dummy') extends StripeClient
        {
            public mixed $paymentIntentsMock = null;

            public function __get($name)
            {
                return $name === 'paymentIntents' ? $this->paymentIntentsMock : parent::__get($name);
            }
        };
        $client->paymentIntentsMock = $paymentIntents;

        $factory = Mockery::mock(StripeClientFactory::class);
        $factory->shouldReceive('createForPlatform')->andReturn($client);
        $this->app->instance(StripeClientFactory::class, $factory);

        return $paymentIntents;
    }
}
