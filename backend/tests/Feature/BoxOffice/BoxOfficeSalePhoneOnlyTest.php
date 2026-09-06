<?php

declare(strict_types=1);

namespace Tests\Feature\BoxOffice;

use HiEvents\Models\Attendee;
use HiEvents\Models\BoxOfficeSale;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\Support\BoxOfficeTestFixtures;
use Tests\TestCase;

class BoxOfficeSalePhoneOnlyTest extends TestCase
{
    use DatabaseTransactions;
    use BoxOfficeTestFixtures;

    private const PASSWORD = 'password123!';

    /** @return array{0: \HiEvents\Models\Event, 1: \HiEvents\Models\Product, 2: \HiEvents\Models\ProductPrice, 3: string} */
    private function authenticatedEvent(): array
    {
        [$event, $product, $productPrice, $user] = $this->createEventWithProduct(price: 25.00, userPassword: self::PASSWORD);
        $this->attachCheckInList($event, $product);
        $token = $this->loginAndGetToken($user, self::PASSWORD);

        return [$event, $product, $productPrice, $token];
    }

    public function test_sale_with_only_phone_succeeds(): void
    {
        [$event, $product, $productPrice, $token] = $this->authenticatedEvent();

        $response = $this->postJson(
            "/events/{$event->id}/box-office-sales",
            [
                'product_id' => $product->id,
                'product_price_id' => $productPrice->id,
                'phone' => '+33 6 12 34 56 78',
                'locale' => 'fr',
                'payment_method' => 'CASH',
                'amount' => 25.00,
                'amount_collected' => 25.00,
                'idempotency_key' => Str::uuid()->toString(),
            ],
            ['Authorization' => 'Bearer ' . $token],
        );

        $response->assertCreated();

        $sale = BoxOfficeSale::query()->where('event_id', $event->id)->first();
        self::assertSame('+33612345678', $sale?->phone);

        $attendee = Attendee::query()->find($sale->attendee_id);
        self::assertSame('', $attendee->first_name);
        self::assertSame('', $attendee->last_name);
        self::assertStringContainsString('33612345678', $attendee->email);
        self::assertStringEndsWith('@guichet.example.test', $attendee->email);
    }

    public function test_sale_without_phone_succeeds(): void
    {
        [$event, $product, $productPrice, $token] = $this->authenticatedEvent();

        $response = $this->postJson(
            "/events/{$event->id}/box-office-sales",
            [
                'product_id' => $product->id,
                'product_price_id' => $productPrice->id,
                'locale' => 'fr',
                'payment_method' => 'CASH',
                'amount' => 25.00,
                'amount_collected' => 25.00,
                'idempotency_key' => Str::uuid()->toString(),
            ],
            ['Authorization' => 'Bearer ' . $token],
        );

        $response->assertCreated();

        $sale = BoxOfficeSale::query()->where('event_id', $event->id)->first();
        self::assertNull($sale?->phone);
    }
}
