<?php

declare(strict_types=1);

namespace Tests\Feature\BoxOffice;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\Support\BoxOfficeTestFixtures;
use Tests\TestCase;

/**
 * TDD (red) — Kiosk v2, D23 Option 1 (PICHA_KIOSK_V2_DECISIONS.md parcours v2.1 §C.1).
 *
 * The `BOX_OFFICE_OPERATOR` role, the `event_box_office_operators` table, the
 * negative guard in `validateUserRole()` and `validateBoxOfficeEventScope()`
 * do not exist yet. Today an operator login blows up on
 * `Role::from('BOX_OFFICE_OPERATOR')` (LoginService), so `loginAndGetToken()`
 * returns null and these requests 401/500 instead of the asserted codes —
 * every test is red. This file MUST be shown red before any application code.
 */
class BoxOfficeOperatorScopeTest extends TestCase
{
    use DatabaseTransactions;
    use BoxOfficeTestFixtures;

    private const PASSWORD = 'password123!';

    private function salePayload(int $productId, int $productPriceId): array
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
            'idempotency_key' => Str::uuid()->toString(),
        ];
    }

    /** AC-v2-1 */
    public function test_operator_can_create_sale_on_assigned_event(): void
    {
        [$event, $product, $productPrice] = $this->createEventWithProduct(price: 25.00);
        $this->attachCheckInList($event, $product);
        $operator = $this->makeBoxOfficeOperator($event, self::PASSWORD);
        $token = $this->loginAndGetToken($operator, self::PASSWORD);

        $this->postJson(
            "/events/{$event->id}/box-office-sales",
            $this->salePayload($product->id, $productPrice->id),
            ['Authorization' => 'Bearer ' . $token],
        )->assertStatus(201);
    }

    /** AC-v2-2 */
    public function test_operator_cannot_create_sale_on_unassigned_event(): void
    {
        [$eventA, , ] = $this->createEventWithProduct(price: 25.00);
        [$eventB, $productB, $productPriceB] = $this->createEventWithProduct(price: 25.00);
        $this->attachCheckInList($eventB, $productB);

        // operator assigned to A only
        $operator = $this->makeBoxOfficeOperator($eventA, self::PASSWORD);
        $token = $this->loginAndGetToken($operator, self::PASSWORD);

        $this->postJson(
            "/events/{$eventB->id}/box-office-sales",
            $this->salePayload($productB->id, $productPriceB->id),
            ['Authorization' => 'Bearer ' . $token],
        )->assertStatus(403);
    }

    /** AC-v2-3 — native ORGANIZER-gated read endpoints are closed to the operator */
    public function test_operator_gets_403_on_native_orders_endpoint(): void
    {
        [$event, , ] = $this->createEventWithProduct(price: 25.00);
        $operator = $this->makeBoxOfficeOperator($event, self::PASSWORD);
        $token = $this->loginAndGetToken($operator, self::PASSWORD);

        $this->getJson("/events/{$event->id}/orders", ['Authorization' => 'Bearer ' . $token])
            ->assertStatus(403);
    }

    /** AC-v2-3 */
    public function test_operator_gets_403_on_native_stats_endpoint(): void
    {
        [$event, , ] = $this->createEventWithProduct(price: 25.00);
        $operator = $this->makeBoxOfficeOperator($event, self::PASSWORD);
        $token = $this->loginAndGetToken($operator, self::PASSWORD);

        $this->getJson("/events/{$event->id}/stats", ['Authorization' => 'Bearer ' . $token])
            ->assertStatus(403);
    }

    /** AC-v2-3 */
    public function test_operator_gets_403_on_event_settings_endpoint(): void
    {
        [$event, , ] = $this->createEventWithProduct(price: 25.00);
        $operator = $this->makeBoxOfficeOperator($event, self::PASSWORD);
        $token = $this->loginAndGetToken($operator, self::PASSWORD);

        $this->getJson("/events/{$event->id}/settings", ['Authorization' => 'Bearer ' . $token])
            ->assertStatus(403);
    }

    /** AC-v2-3 */
    public function test_operator_gets_403_on_orders_export(): void
    {
        [$event, , ] = $this->createEventWithProduct(price: 25.00);
        $operator = $this->makeBoxOfficeOperator($event, self::PASSWORD);
        $token = $this->loginAndGetToken($operator, self::PASSWORD);

        $this->postJson("/events/{$event->id}/orders/export", [], ['Authorization' => 'Bearer ' . $token])
            ->assertStatus(403);
    }

    /** AC-v2-3 — cannot enumerate the account's events */
    public function test_operator_gets_403_on_events_list(): void
    {
        [$event, , ] = $this->createEventWithProduct(price: 25.00);
        $operator = $this->makeBoxOfficeOperator($event, self::PASSWORD);
        $token = $this->loginAndGetToken($operator, self::PASSWORD);

        $this->getJson('/events', ['Authorization' => 'Bearer ' . $token])
            ->assertStatus(403);
    }

    /** AC-v2-4 — an operator cannot create another operator, even for its own event */
    public function test_operator_cannot_create_another_operator(): void
    {
        [$event, , ] = $this->createEventWithProduct(price: 25.00);
        $operator = $this->makeBoxOfficeOperator($event, self::PASSWORD);
        $token = $this->loginAndGetToken($operator, self::PASSWORD);

        $this->postJson(
            "/events/{$event->id}/box-office/operators",
            ['first_name' => 'Bob', 'last_name' => 'Smith', 'email' => 'bob@example.test'],
            ['Authorization' => 'Bearer ' . $token],
        )->assertStatus(403);
    }

    /** AC-v2-22 — an ORGANIZER connected to the shell keeps the native endpoints */
    public function test_organizer_still_reaches_native_orders_endpoint(): void
    {
        [$event, , ] = $this->createEventWithProduct(price: 25.00);
        $organizer = $this->makeOrganizerOnEvent($event, self::PASSWORD);
        $token = $this->loginAndGetToken($organizer, self::PASSWORD);

        // ORGANIZER: 200 on native orders.
        $this->getJson("/events/{$event->id}/orders", ['Authorization' => 'Bearer ' . $token])
            ->assertStatus(200);

        // …while the operator on the same event is refused (red today).
        $operator = $this->makeBoxOfficeOperator($event, self::PASSWORD);
        $operatorToken = $this->loginAndGetToken($operator, self::PASSWORD);

        $this->getJson("/events/{$event->id}/orders", ['Authorization' => 'Bearer ' . $operatorToken])
            ->assertStatus(403);
    }

    /** AC-v2-6 — the sale's agent_user_id is the operator's real users.id (no schema change) */
    public function test_sale_agent_user_id_is_the_operator_user_id(): void
    {
        [$event, $product, $productPrice] = $this->createEventWithProduct(price: 25.00);
        $this->attachCheckInList($event, $product);
        $operator = $this->makeBoxOfficeOperator($event, self::PASSWORD);
        $token = $this->loginAndGetToken($operator, self::PASSWORD);

        $this->postJson(
            "/events/{$event->id}/box-office-sales",
            $this->salePayload($product->id, $productPrice->id),
            ['Authorization' => 'Bearer ' . $token],
        )->assertStatus(201);

        $this->assertSame(
            1,
            \Illuminate\Support\Facades\DB::table('box_office_sales')
                ->where('event_id', $event->id)
                ->where('agent_user_id', $operator->id)
                ->count(),
        );
    }
}
