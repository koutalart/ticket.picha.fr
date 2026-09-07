<?php

declare(strict_types=1);

namespace Tests\Feature\BoxOffice;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\Support\BoxOfficeTestFixtures;
use Tests\TestCase;

/**
 * TDD (red) — Kiosk v2, D23 multi-events + selector (PICHA_KIOSK_V2_DECISIONS.md
 * parcours v2.1 §C.2).
 *
 * `GET /box-office/context`, the `event_box_office_operators` table and the
 * `BOX_OFFICE_OPERATOR` role do not exist yet — every test is red. Must be
 * shown red before any application code.
 */
class BoxOfficeOperatorMultiEventTest extends TestCase
{
    use BoxOfficeTestFixtures;
    use DatabaseTransactions;

    private const PASSWORD = 'password123!';

    private function salePayload(int $productId, int $productPriceId): array
    {
        return [
            'product_id' => $productId,
            'product_price_id' => $productPriceId,
            'phone' => '+33612345678',
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

    /** AC-v2-8 — context returns exactly the operator's ACTIVE assignments, nothing else */
    public function test_context_returns_only_assigned_active_events(): void
    {
        [$eventA] = $this->createEventWithProduct(price: 25.00);
        [$eventB] = $this->createEventWithProductOnAccount($eventA->account_id);
        // A third event on the SAME account the operator is NOT assigned to.
        $this->createEventWithProductOnAccount($eventA->account_id);

        $operator = $this->makeBoxOfficeOperator($eventA, self::PASSWORD);
        $this->assignOperatorToEvent($operator, $eventB, status: 'ACTIVE');
        $token = $this->loginAndGetToken($operator, self::PASSWORD);

        $response = $this->getJson('/box-office/context', ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(200);
        $ids = collect($response->json('data'))->pluck('id')->sort()->values()->all();
        $this->assertSame([$eventA->id, $eventB->id], $ids);
    }

    /** AC-v2-10 — a single assignment yields exactly one context entry */
    public function test_single_assignment_yields_one_context_entry(): void
    {
        [$event] = $this->createEventWithProduct(price: 25.00);
        $operator = $this->makeBoxOfficeOperator($event, self::PASSWORD);
        $token = $this->loginAndGetToken($operator, self::PASSWORD);

        $this->getJson('/box-office/context', ['Authorization' => 'Bearer '.$token])
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    /** AC-v2-11 — all assignments revoked → empty context, no sale possible */
    public function test_revoked_only_assignment_yields_empty_context(): void
    {
        [$event, $product, $productPrice] = $this->createEventWithProduct(price: 25.00);
        $this->attachCheckInList($event, $product);
        $operator = $this->makeBoxOfficeOperator($event, self::PASSWORD, status: 'REVOKED');
        $token = $this->loginAndGetToken($operator, self::PASSWORD);

        $this->getJson('/box-office/context', ['Authorization' => 'Bearer '.$token])
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');

        $this->postJson(
            "/events/{$event->id}/box-office-sales",
            $this->salePayload($product->id, $productPrice->id),
            ['Authorization' => 'Bearer '.$token],
        )->assertStatus(403);
    }

    /** AC-v2-12 — unique(event_id, user_id): no duplicate assignment row */
    public function test_duplicate_assignment_to_same_event_is_rejected(): void
    {
        [$event] = $this->createEventWithProduct(price: 25.00);
        $operator = $this->makeBoxOfficeOperator($event, self::PASSWORD);

        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->assignOperatorToEvent($operator, $event, status: 'ACTIVE');
    }

    /** AC-v2-9 — operator assigned to two events (same account) can sell on the second one */
    public function test_operator_can_sell_on_a_second_assigned_event(): void
    {
        [$eventA] = $this->createEventWithProduct(price: 25.00);
        [$eventB, $productB, $productPriceB] = $this->createEventWithProductOnAccount($eventA->account_id);
        $this->attachCheckInList($eventB, $productB);

        $operator = $this->makeBoxOfficeOperator($eventA, self::PASSWORD);
        $this->assignOperatorToEvent($operator, $eventB, status: 'ACTIVE');
        $token = $this->loginAndGetToken($operator, self::PASSWORD);

        $this->postJson(
            "/events/{$eventB->id}/box-office-sales",
            $this->salePayload($productB->id, $productPriceB->id),
            ['Authorization' => 'Bearer '.$token],
        )->assertStatus(201);
    }

    /** AC-v2-8 — an ORGANIZER's context is not limited to operator assignments */
    public function test_context_for_organizer_is_not_operator_scoped(): void
    {
        [$event] = $this->createEventWithProduct(price: 25.00);
        // second event, same account
        $this->createEventWithProductOnAccount($event->account_id);
        $organizer = $this->makeOrganizerOnEvent($event, self::PASSWORD);
        $token = $this->loginAndGetToken($organizer, self::PASSWORD);

        // No event_box_office_operators rows for this ORGANIZER, yet context must not be empty.
        $response = $this->getJson('/box-office/context', ['Authorization' => 'Bearer '.$token]);
        $response->assertStatus(200);
        $this->assertGreaterThanOrEqual(1, count($response->json('data')));
    }
}
