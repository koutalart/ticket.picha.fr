<?php

namespace Tests\Unit\Repository;

use HiEvents\Helper\IdHelper;
use HiEvents\Models\Event;
use HiEvents\Models\Order;
use HiEvents\Models\Product;
use HiEvents\Models\ProductPrice;
use HiEvents\Repository\Eloquent\OrderRepository;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Support\BoxOfficeTestFixtures;
use Tests\TestCase;

class RecentOnlineTicketPurchasesTest extends TestCase
{
    use BoxOfficeTestFixtures;
    use DatabaseTransactions;

    public function test_only_recent_completed_online_ticket_orders_are_returned_newest_first(): void
    {
        [$event, $product, $price, $user] = $this->createEventWithProduct();

        $this->order($event, $product, $price, 2, now()->subMinutes(30));
        $this->order($event, $product, $price, 3, now()->subMinutes(5));
        $this->order($event, $product, $price, 1, now()->subHours(50));
        $this->order($event, $product, $price, 4, now()->subMinutes(10), status: 'RESERVED');
        $this->order($event, $product, $price, 4, now()->subMinutes(10), status: 'CANCELLED');
        $this->order($event, $product, $price, 5, now()->subMinutes(12), manual: true);
        $boxOffice = $this->order($event, $product, $price, 6, now()->subMinutes(15));
        $this->boxOfficeSale($event, $boxOffice, $user->id);

        $purchases = $this->repository()->findRecentOnlineTicketPurchases($event->id, now()->subHours(48), 10);

        $this->assertSame([3, 2], $purchases->pluck('quantity')->all());
    }

    public function test_orders_of_other_events_and_non_ticket_items_are_ignored(): void
    {
        [$event, $product, $price] = $this->createEventWithProduct();
        [$otherEvent, $otherProduct, $otherPrice] = $this->createEventWithProduct();

        $protection = Product::create([
            'event_id' => $event->id,
            'title' => 'Option sérénité',
            'product_type' => 'GENERAL',
            'type' => 'PAID',
            'order' => 2,
            'is_refund_protection' => true,
        ]);
        $protectionPrice = ProductPrice::create(['product_id' => $protection->id, 'price' => 1, 'order' => 1]);

        $this->order($event, $protection, $protectionPrice, 1, now()->subMinutes(3), productType: 'GENERAL');
        $this->order($otherEvent, $otherProduct, $otherPrice, 2, now()->subMinutes(4));
        $mixed = $this->order($event, $product, $price, 2, now()->subMinutes(8));
        $this->item($mixed, $protection, $protectionPrice, 2, 'GENERAL');

        $purchases = $this->repository()->findRecentOnlineTicketPurchases($event->id, now()->subHours(48), 10);

        $this->assertSame([2], $purchases->pluck('quantity')->all());
    }

    public function test_limit_is_respected(): void
    {
        [$event, $product, $price] = $this->createEventWithProduct();

        foreach (range(1, 4) as $minutes) {
            $this->order($event, $product, $price, 1, now()->subMinutes($minutes));
        }

        $this->assertCount(2, $this->repository()->findRecentOnlineTicketPurchases($event->id, now()->subHours(48), 2));
    }

    private function repository(): OrderRepository
    {
        return $this->app->make(OrderRepository::class);
    }

    private function order(
        Event $event,
        Product $product,
        ProductPrice $price,
        int $quantity,
        \DateTimeInterface $createdAt,
        string $status = 'COMPLETED',
        bool $manual = false,
        string $productType = 'TICKET',
    ): Order {
        $order = Order::create([
            'event_id' => $event->id,
            'total_gross' => 10 * $quantity,
            'currency' => 'EUR',
            'status' => $status,
            'payment_status' => 'PAYMENT_RECEIVED',
            'is_manually_created' => $manual,
            'first_name' => 'Test',
            'last_name' => 'Buyer',
            'email' => 'buyer@example.test',
            'locale' => 'fr',
            'short_id' => IdHelper::shortId(IdHelper::ORDER_PREFIX),
            'public_id' => IdHelper::publicId(IdHelper::ORDER_PREFIX),
        ]);
        Order::whereKey($order->id)->update(['created_at' => $createdAt]);

        $this->item($order, $product, $price, $quantity, $productType);

        return $order;
    }

    private function item(Order $order, Product $product, ProductPrice $price, int $quantity, string $productType): void
    {
        DB::table('order_items')->insert([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_price_id' => $price->id,
            'quantity' => $quantity,
            'item_name' => $product->title,
            'price' => 10,
            'total_before_additions' => 10 * $quantity,
            'total_gross' => 10 * $quantity,
            'product_type' => $productType,
        ]);
    }

    private function boxOfficeSale(Event $event, Order $order, int $agentUserId): void
    {
        DB::table('box_office_sales')->insert([
            'idempotency_key' => uniqid('test-', true),
            'event_id' => $event->id,
            'agent_user_id' => $agentUserId,
            'order_id' => $order->id,
            'status' => 'COMPLETED',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
