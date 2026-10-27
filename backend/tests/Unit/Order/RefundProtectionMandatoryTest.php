<?php

declare(strict_types=1);

namespace Tests\Unit\Order;

use HiEvents\Models\Event;
use HiEvents\Models\Product;
use HiEvents\Models\ProductPrice;
use HiEvents\Services\Domain\Order\RefundProtection\OrderRefundProtectionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\BoxOfficeTestFixtures;
use Tests\TestCase;

class RefundProtectionMandatoryTest extends TestCase
{
    use BoxOfficeTestFixtures;
    use DatabaseTransactions;

    private const SESSION = 'session-refund-protection-mandatory';

    private Event $event;

    private Product $ticket;

    private ProductPrice $ticketPrice;

    private Product $vip;

    private ProductPrice $vipPrice;

    private Product $protection;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.saas_mode_enabled' => false, 'app.refund_protection_mandatory_above' => 15.00]);

        [$this->event, $this->ticket, $this->ticketPrice] = $this->createEventWithProduct(price: 10.00);
        DB::table('events')->where('id', $this->event->id)->update([
            'currency' => 'EUR',
            'timezone' => 'UTC',
            'start_date' => now()->addDays(4),
            'end_date' => now()->addDays(4)->addHours(6),
        ]);

        $this->vip = Product::create([
            'event_id' => $this->event->id,
            'title' => 'VIP',
            'product_type' => 'TICKET',
            'type' => 'PAID',
            'order' => 2,
        ]);
        $this->vipPrice = ProductPrice::create([
            'product_id' => $this->vip->id,
            'price' => 20.00,
            'initial_quantity_available' => 100,
            'quantity_sold' => 0,
            'order' => 1,
        ]);

        $this->protection = Product::create([
            'event_id' => $this->event->id,
            'title' => 'Option sérénité',
            'product_type' => 'GENERAL',
            'type' => 'PAID',
            'order' => 3,
            'is_refund_protection' => true,
        ]);
        ProductPrice::create([
            'product_id' => $this->protection->id,
            'price' => 0.49,
            'initial_quantity_available' => null,
            'quantity_sold' => 0,
            'order' => 1,
        ]);
    }

    public function test_a_ticket_above_the_threshold_gets_one_locked_protection_per_ticket(): void
    {
        $orderShortId = $this->createOrder(vip: 2);

        $this->assertOrderTotal($orderShortId, 40.98);
        $this->assertSame(2, $this->protectionQuantity($orderShortId));

        $this->getOffer($orderShortId)
            ->assertOk()
            ->assertJsonPath('data.is_selected', true)
            ->assertJsonPath('data.is_locked', true)
            ->assertJsonPath('data.mandatory_quantity', 2)
            ->assertJsonPath('data.mandatory_total_gross', 0.98)
            ->assertJsonPath('data.mandatory_above', 15);

        $this->setProtection($orderShortId, false)->assertOk();
        $this->assertOrderTotal($orderShortId, 40.98);

        $this->assertEquals(
            ['mandatory_quantity' => 2, 'optional_selected' => false, 'mandatory_above' => 15.0],
            $this->snapshot($orderShortId),
        );
    }

    public function test_a_ticket_at_the_threshold_keeps_the_optional_checkbox(): void
    {
        $this->ticketPrice->update(['price' => 15.00]);

        $orderShortId = $this->createOrder(tickets: 2);

        $this->assertOrderTotal($orderShortId, 30.00);
        $this->getOffer($orderShortId)
            ->assertJsonPath('data.is_selected', false)
            ->assertJsonPath('data.is_locked', false)
            ->assertJsonPath('data.mandatory_quantity', 0)
            ->assertJsonPath('data.quantity', 1)
            ->assertJsonPath('data.total_before_additions', 0.49);
    }

    public function test_a_mixed_order_combines_the_mandatory_part_and_the_checkbox(): void
    {
        $orderShortId = $this->createOrder(tickets: 2, vip: 1);

        $this->assertOrderTotal($orderShortId, 40.49);
        $this->getOffer($orderShortId)
            ->assertJsonPath('data.mandatory_quantity', 1)
            ->assertJsonPath('data.mandatory_total_gross', 0.49)
            ->assertJsonPath('data.quantity', 1)
            ->assertJsonPath('data.total_gross', 0.49)
            ->assertJsonPath('data.is_selected', false)
            ->assertJsonPath('data.is_locked', false);

        $this->setProtection($orderShortId, true)
            ->assertOk()
            ->assertJsonPath('data.is_selected', true);
        $this->assertOrderTotal($orderShortId, 40.98);
        $this->assertSame(2, $this->protectionQuantity($orderShortId));
        $this->assertTrue($this->snapshot($orderShortId)['optional_selected']);

        $this->setProtection($orderShortId, false)->assertOk()->assertJsonPath('data.is_selected', false);
        $this->assertOrderTotal($orderShortId, 40.49);
        $this->assertSame(1, $this->protectionQuantity($orderShortId));
        $this->assertFalse($this->snapshot($orderShortId)['optional_selected']);
    }

    public function test_per_ticket_mode_adds_one_protection_per_remaining_ticket(): void
    {
        $this->protection->update(['required_quantity_mode' => 'PER_TICKET']);

        $orderShortId = $this->createOrder(tickets: 2, vip: 1);
        $this->getOffer($orderShortId)
            ->assertJsonPath('data.quantity', 2)
            ->assertJsonPath('data.total_before_additions', 0.98);

        $this->setProtection($orderShortId, true)->assertOk();
        $this->assertOrderTotal($orderShortId, 41.47);
        $this->assertSame(3, $this->protectionQuantity($orderShortId));
    }

    public function test_the_threshold_comes_from_the_configuration(): void
    {
        config(['app.refund_protection_mandatory_above' => 25.00]);

        $orderShortId = $this->createOrder(vip: 1);

        $this->assertOrderTotal($orderShortId, 20.00);
        $this->assertSame(0, $this->protectionQuantity($orderShortId));
    }

    public function test_a_promo_code_does_not_change_the_tariff_used_for_the_threshold(): void
    {
        DB::table('promo_codes')->insert([
            'event_id' => $this->event->id,
            'code' => 'moins10',
            'discount' => 10,
            'discount_type' => 'FIXED',
            'applicable_product_ids' => json_encode([$this->vip->id]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $orderShortId = $this->createOrder(vip: 1, promoCode: 'moins10');

        $this->assertOrderTotal($orderShortId, 10.49);
        $this->assertSame(1, $this->protectionQuantity($orderShortId));
    }

    public function test_the_public_ticket_price_includes_the_mandatory_protection(): void
    {
        $prices = $this->publicPrices();

        $this->assertEqualsWithDelta(0.49, $prices[$this->vipPrice->id]['refund_protection_included'], 0.001);
        $this->assertNull($prices[$this->ticketPrice->id]['refund_protection_included']);
    }

    public function test_a_protection_required_by_the_organizer_stays_required_below_the_threshold(): void
    {
        $this->ticketPrice->update(['price' => 15.00]);
        $this->protection->update(['is_required' => true, 'required_quantity_mode' => 'PER_TICKET']);

        $orderShortId = $this->createOrder(tickets: 2);

        $this->assertOrderTotal($orderShortId, 30.98);
        $this->getOffer($orderShortId)
            ->assertJsonPath('data.is_locked', true)
            ->assertJsonPath('data.is_required_by_organizer', true)
            ->assertJsonPath('data.mandatory_quantity', 0);
        $this->setProtection($orderShortId, false)->assertStatus(422);
        $this->assertNull($this->snapshot($orderShortId));

        $prices = $this->publicPrices();
        $this->assertEqualsWithDelta(0.49, $prices[$this->ticketPrice->id]['refund_protection_included'], 0.001);
    }

    private function createOrder(int $tickets = 0, int $vip = 0, ?string $promoCode = null): string
    {
        $products = [];

        if ($tickets > 0) {
            $products[] = [
                'product_id' => $this->ticket->id,
                'quantities' => [['price_id' => $this->ticketPrice->id, 'quantity' => $tickets]],
            ];
        }

        if ($vip > 0) {
            $products[] = [
                'product_id' => $this->vip->id,
                'quantities' => [['price_id' => $this->vipPrice->id, 'quantity' => $vip]],
            ];
        }

        $payload = ['products' => $products];

        if ($promoCode !== null) {
            $payload['promo_code'] = $promoCode;
        }

        $response = $this->postJson("/public/events/{$this->event->id}/order?session_identifier=".self::SESSION, $payload);
        $response->assertCreated();

        return $response->json('data.short_id');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function publicPrices(): array
    {
        $categoryId = DB::table('product_categories')->insertGetId([
            'name' => 'Billets',
            'event_id' => $this->event->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('products')->where('event_id', $this->event->id)->update(['product_category_id' => $categoryId]);

        $response = $this->getJson("/public/events/{$this->event->id}")->assertOk();

        return collect($response->json('data.product_categories') ?? [])
            ->flatMap(fn (array $category) => $category['products'] ?? [])
            ->flatMap(fn (array $product) => $product['prices'] ?? [])
            ->keyBy('id')
            ->all();
    }

    private function url(string $orderShortId): string
    {
        return "/public/events/{$this->event->id}/order/{$orderShortId}/refund-protection?session_identifier=".self::SESSION;
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

    private function protectionQuantity(string $orderShortId): int
    {
        return (int) DB::table('order_items')
            ->where('order_id', $this->orderId($orderShortId))
            ->where('product_id', $this->protection->id)
            ->whereNull('deleted_at')
            ->sum('quantity');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function snapshot(string $orderShortId): ?array
    {
        $data = json_decode((string) DB::table('orders')->where('short_id', $orderShortId)->value('point_in_time_data'), true);

        return is_array($data) ? ($data[OrderRefundProtectionService::SNAPSHOT_KEY] ?? null) : null;
    }
}
