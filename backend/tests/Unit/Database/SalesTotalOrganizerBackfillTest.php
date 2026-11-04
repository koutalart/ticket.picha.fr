<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use HiEvents\DomainObjects\Enums\ProductType;
use HiEvents\Helper\IdHelper;
use HiEvents\Models\Event;
use HiEvents\Models\Order;
use HiEvents\Models\Product;
use HiEvents\Models\ProductPrice;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Support\BoxOfficeTestFixtures;
use Tests\TestCase;

/**
 * History of the organizer sales (migration 2026_11_04_120000): same orders as sales_total_gross,
 * protection taken at its price without the fees it carried.
 */
class SalesTotalOrganizerBackfillTest extends TestCase
{
    use BoxOfficeTestFixtures;
    use DatabaseTransactions;

    public function test_history_leaves_out_offline_orders_and_does_not_subtract_fees_twice(): void
    {
        [$event, $ticket, $ticketPrice] = $this->createEventWithProduct(price: 20.00);
        [$protection, $protectionPrice] = $this->protectionProduct($event);

        // Paid online: ticket 20 + fee 0,99, old protection 0,49 carrying 0,49 of fees => organizer 20
        $paid = $this->order($event, 'COMPLETED', 'PAYMENT_RECEIVED', totalGross: 21.97, totalFee: 1.48);
        $this->item($paid, $ticket->id, $ticketPrice->id, beforeAdditions: 20.00, serviceFee: 0.99, gross: 20.99);
        $this->item($paid, $protection->id, $protectionPrice->id, beforeAdditions: 0.49, serviceFee: 0.49, gross: 0.98);

        // Paid, then one ticket refunded => organizer 20 - 20
        $refunded = $this->order($event, 'COMPLETED', 'PAYMENT_RECEIVED', totalGross: 20.99, totalFee: 0.99, totalRefunded: 20.00);
        $this->item($refunded, $ticket->id, $ticketPrice->id, beforeAdditions: 20.00, serviceFee: 0.99, gross: 20.99);

        // Paper ticket awaiting the offline payment: never in the sales
        $offline = $this->order($event, 'COMPLETED', 'AWAITING_OFFLINE_PAYMENT', totalGross: 20.00, totalFee: 0.0);
        $this->item($offline, $ticket->id, $ticketPrice->id, beforeAdditions: 20.00, serviceFee: 0.0, gross: 20.00);

        // Free registration
        $this->order($event, 'COMPLETED', 'NO_PAYMENT_REQUIRED', totalGross: 0.0, totalFee: 0.0);

        DB::table('event_statistics')->updateOrInsert(['event_id' => $event->id], [
            'sales_total_gross' => 22.96, 'products_sold' => 3, 'orders_created' => 3, 'attendees_registered' => 3,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('event_daily_statistics')->insert([
            'event_id' => $event->id, 'date' => now()->toDateString(), 'sales_total_gross' => 22.96,
            'products_sold' => 3, 'orders_created' => 3, 'attendees_registered' => 3,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $migration = require base_path('database/migrations/2026_11_04_120000_add_sales_total_organizer_to_event_statistics.php');
        $migration->backfill();

        self::assertEqualsWithDelta(20.00, (float) DB::table('event_statistics')->where('event_id', $event->id)->value('sales_total_organizer'), 0.001);
        self::assertEqualsWithDelta(20.00, (float) DB::table('event_daily_statistics')->where('event_id', $event->id)->value('sales_total_organizer'), 0.001);
    }

    /**
     * @return array{0: Product, 1: ProductPrice}
     */
    private function protectionProduct(Event $event): array
    {
        $product = Product::create([
            'event_id' => $event->id,
            'title' => 'Option sérénité',
            'product_type' => ProductType::GENERAL->name,
            'type' => 'PAID',
            'order' => 2,
            'is_refund_protection' => true,
        ]);

        $price = ProductPrice::create([
            'product_id' => $product->id,
            'price' => 0.49,
            'initial_quantity_available' => null,
            'quantity_sold' => 0,
            'order' => 1,
        ]);

        return [$product, $price];
    }

    private function order(Event $event, string $status, string $paymentStatus, float $totalGross, float $totalFee, float $totalRefunded = 0.0): Order
    {
        return Order::create([
            'event_id' => $event->id,
            'total_gross' => $totalGross,
            'total_fee' => $totalFee,
            'total_refunded' => $totalRefunded,
            'currency' => 'USD',
            'status' => $status,
            'payment_status' => $paymentStatus,
            'email' => 'acheteur@example.test',
            'first_name' => 'Anli',
            'last_name' => 'Madi',
            'short_id' => IdHelper::shortId(IdHelper::ORDER_PREFIX),
            'public_id' => IdHelper::publicId(IdHelper::ORDER_PREFIX),
        ]);
    }

    private function item(Order $order, int $productId, int $productPriceId, float $beforeAdditions, float $serviceFee, float $gross): void
    {
        DB::table('order_items')->insert([
            'order_id' => $order->id,
            'product_id' => $productId,
            'product_price_id' => $productPriceId,
            'quantity' => 1,
            'price' => $beforeAdditions,
            'total_before_additions' => $beforeAdditions,
            'total_tax' => 0,
            'total_service_fee' => $serviceFee,
            'total_gross' => $gross,
            'item_name' => 'Item',
        ]);
    }
}
