<?php

declare(strict_types=1);

namespace Tests\Feature\BoxOffice;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\BoxOfficeTestFixtures;
use Tests\TestCase;

class PausedTicketSalesTest extends TestCase
{
    use BoxOfficeTestFixtures;
    use DatabaseTransactions;

    private const PASSWORD = 'password123!';

    public function test_paused_ticket_is_not_sold_at_the_box_office(): void
    {
        [$event, $product, $productPrice, $user] = $this->createEventWithProduct(price: 25.00, userPassword: self::PASSWORD);
        $this->attachCheckInList($event, $product);
        $token = $this->loginAndGetToken($user, self::PASSWORD);
        DB::table('products')->where('id', $product->id)->update(['is_sales_paused' => true]);

        $catalog = $this->getJson("/events/{$event->id}/box-office/products", ['Authorization' => 'Bearer '.$token])->assertOk();
        self::assertCount(0, $catalog->json('data'));

        $this->postJson("/events/{$event->id}/box-office-sales", [
            'items' => [['product_id' => $product->id, 'product_price_id' => $productPrice->id, 'quantity' => 1]],
            'first_name' => 'Jane',
            'locale' => 'fr',
            'payment_method' => 'CASH',
            'amount' => 25.00,
            'amount_collected' => 25.00,
            'idempotency_key' => Str::uuid()->toString(),
        ], ['Authorization' => 'Bearer '.$token])->assertStatus(409)->assertJsonPath('message', __('Sales of this ticket are paused.'));

        self::assertSame(0, DB::table('attendees')->where('product_id', $product->id)->count());
    }

    public function test_editing_a_ticket_keeps_its_pause_unless_told_otherwise(): void
    {
        [$event, $product, $productPrice, $user] = $this->createEventWithProduct(price: 25.00, userPassword: self::PASSWORD);
        $token = $this->loginAndGetToken($user, self::PASSWORD);
        $categoryId = DB::table('product_categories')->insertGetId(['event_id' => $event->id, 'name' => 'Billets', 'order' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('products')->where('id', $product->id)->update(['is_sales_paused' => true, 'product_category_id' => $categoryId]);

        $payload = [
            'title' => 'Entrée',
            'type' => 'PAID',
            'product_type' => 'TICKET',
            'product_category_id' => $categoryId,
            'prices' => [['id' => $productPrice->id, 'price' => 30, 'initial_quantity_available' => 100]],
        ];

        $this->putJson("/events/{$event->id}/products/{$product->id}", $payload, ['Authorization' => 'Bearer '.$token])->assertOk();
        self::assertTrue((bool) DB::table('products')->where('id', $product->id)->value('is_sales_paused'));

        $this->putJson("/events/{$event->id}/products/{$product->id}", [...$payload, 'is_sales_paused' => false], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('data.is_sales_paused', false);
    }
}
