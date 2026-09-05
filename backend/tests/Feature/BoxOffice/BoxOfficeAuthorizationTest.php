<?php

declare(strict_types=1);

namespace Tests\Feature\BoxOffice;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Support\BoxOfficeTestFixtures;
use Tests\TestCase;

/**
 * TDD (red) tests for AC-1: "POST /events/{event_id}/box-office-sales" does
 * not exist yet, so both requests below 404 today instead of behaving as
 * asserted.
 */
class BoxOfficeAuthorizationTest extends TestCase
{
    use DatabaseTransactions;
    use BoxOfficeTestFixtures;

    private const PASSWORD = 'password123!';

    private function payload(int $productId, int $productPriceId): array
    {
        return [
            'product_id' => $productId,
            'product_price_id' => $productPriceId,
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'jane@example.test',
            'locale' => 'en',
            'payment_method' => 'CASH',
            'amount' => 25.00,
            'amount_collected' => 25.00,
            'send_confirmation_email' => false,
            'idempotency_key' => \Illuminate\Support\Str::uuid()->toString(),
        ];
    }

    /** AC-1 */
    public function test_organizer_can_create_sale(): void
    {
        [$event, $product, $productPrice, $user] = $this->createEventWithProduct(price: 25.00, userPassword: self::PASSWORD);
        $this->attachCheckInList($event, $product);
        $token = $this->loginAndGetToken($user, self::PASSWORD);

        $response = $this->postJson(
            "/events/{$event->id}/box-office-sales",
            $this->payload($product->id, $productPrice->id),
            ['Authorization' => 'Bearer ' . $token],
        );

        $response->assertStatus(201);
    }

    /** AC-1 */
    public function test_other_account_user_gets_403(): void
    {
        [$event, $product, $productPrice] = $this->createEventWithProduct(price: 25.00);
        $this->attachCheckInList($event, $product);

        $otherUser = $this->createUnrelatedOrganizerUser(self::PASSWORD);
        $token = $this->loginAndGetToken($otherUser, self::PASSWORD);

        $response = $this->postJson(
            "/events/{$event->id}/box-office-sales",
            $this->payload($product->id, $productPrice->id),
            ['Authorization' => 'Bearer ' . $token],
        );

        $response->assertStatus(403);
    }
}
