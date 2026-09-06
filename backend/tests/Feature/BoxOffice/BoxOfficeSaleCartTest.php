<?php

declare(strict_types=1);

namespace Tests\Feature\BoxOffice;

use HiEvents\Models\Attendee;
use HiEvents\Models\BoxOfficeSale;
use HiEvents\Models\BoxOfficeSaleItem;
use HiEvents\Models\ProductPrice;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\Support\BoxOfficeTestFixtures;
use Tests\TestCase;

class BoxOfficeSaleCartTest extends TestCase
{
    use DatabaseTransactions;
    use BoxOfficeTestFixtures;

    private const PASSWORD = 'password123!';

    public function test_cart_of_two_tickets_is_one_sale_and_two_attendees(): void
    {
        [$event, $product, $productPrice, $user] = $this->createEventWithProduct(
            price: 25.00,
            initialQuantityAvailable: 10,
            userPassword: self::PASSWORD,
        );
        $this->attachCheckInList($event, $product);
        $token = $this->loginAndGetToken($user, self::PASSWORD);
        $key = Str::uuid()->toString();

        $response = $this->postJson(
            "/events/{$event->id}/box-office-sales",
            [
                'items' => [
                    [
                        'product_id' => $product->id,
                        'product_price_id' => $productPrice->id,
                        'quantity' => 2,
                    ],
                ],
                'locale' => 'fr',
                'payment_method' => 'CASH',
                'amount' => 50.00,
                'amount_collected' => 50.00,
                'idempotency_key' => $key,
            ],
            ['Authorization' => 'Bearer ' . $token],
        );

        $response->assertCreated();
        $response->assertJsonCount(2, 'data.attendees');

        self::assertSame(1, BoxOfficeSale::query()->where('event_id', $event->id)->count());
        self::assertSame(2, BoxOfficeSaleItem::query()->where('box_office_sale_id', $response->json('data.sale_id'))->count());
        self::assertSame(2, Attendee::query()->where('event_id', $event->id)->count());
        self::assertSame(2, ProductPrice::query()->find($productPrice->id)?->quantity_sold);

        $replay = $this->postJson(
            "/events/{$event->id}/box-office-sales",
            [
                'items' => [
                    [
                        'product_id' => $product->id,
                        'product_price_id' => $productPrice->id,
                        'quantity' => 2,
                    ],
                ],
                'locale' => 'fr',
                'payment_method' => 'CASH',
                'amount' => 50.00,
                'amount_collected' => 50.00,
                'idempotency_key' => $key,
            ],
            ['Authorization' => 'Bearer ' . $token],
        );

        $replay->assertCreated();
        self::assertSame($response->json('data.sale_id'), $replay->json('data.sale_id'));
        self::assertSame(2, ProductPrice::query()->find($productPrice->id)?->quantity_sold);
    }

    public function test_cart_qty_beyond_stock_creates_nothing(): void
    {
        [$event, $product, $productPrice, $user] = $this->createEventWithProduct(
            price: 25.00,
            initialQuantityAvailable: 1,
            userPassword: self::PASSWORD,
        );
        $this->attachCheckInList($event, $product);
        $token = $this->loginAndGetToken($user, self::PASSWORD);

        $response = $this->postJson(
            "/events/{$event->id}/box-office-sales",
            [
                'items' => [
                    [
                        'product_id' => $product->id,
                        'product_price_id' => $productPrice->id,
                        'quantity' => 2,
                    ],
                ],
                'locale' => 'fr',
                'payment_method' => 'CASH',
                'amount' => 50.00,
                'amount_collected' => 50.00,
                'idempotency_key' => Str::uuid()->toString(),
            ],
            ['Authorization' => 'Bearer ' . $token],
        );

        $response->assertConflict();
        self::assertSame(0, BoxOfficeSale::query()->where('event_id', $event->id)->count());
        self::assertSame(0, Attendee::query()->where('event_id', $event->id)->count());
        self::assertSame(0, ProductPrice::query()->find($productPrice->id)?->quantity_sold);
    }

    public function test_cart_amount_must_match_server_total(): void
    {
        [$event, $product, $productPrice, $user] = $this->createEventWithProduct(
            price: 25.00,
            userPassword: self::PASSWORD,
        );
        $this->attachCheckInList($event, $product);
        $token = $this->loginAndGetToken($user, self::PASSWORD);

        $response = $this->postJson(
            "/events/{$event->id}/box-office-sales",
            [
                'items' => [
                    [
                        'product_id' => $product->id,
                        'product_price_id' => $productPrice->id,
                        'quantity' => 2,
                    ],
                ],
                'locale' => 'fr',
                'payment_method' => 'CASH',
                'amount' => 25.00,
                'amount_collected' => 25.00,
                'idempotency_key' => Str::uuid()->toString(),
            ],
            ['Authorization' => 'Bearer ' . $token],
        );

        $response->assertUnprocessable();
        self::assertSame(0, BoxOfficeSale::query()->where('event_id', $event->id)->count());
    }
}
