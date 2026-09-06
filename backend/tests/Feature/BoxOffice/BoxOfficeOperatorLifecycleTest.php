<?php

declare(strict_types=1);

namespace Tests\Feature\BoxOffice;

use HiEvents\DomainObjects\Enums\Role;
use HiEvents\Mail\User\UserInvited;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Support\BoxOfficeTestFixtures;
use Tests\TestCase;

/**
 * TDD (red) — Kiosk v2, D23 operator lifecycle (PICHA_KIOSK_V2_DECISIONS.md
 * parcours v2.1 §C.3).
 *
 * `POST/GET/PATCH /events/{id}/box-office/operators` (ADMIN only), the
 * `event_box_office_operators` table and the `BOX_OFFICE_OPERATOR` role do
 * not exist yet. Every test is expected to 404 / error until slice v2.1
 * lands. Must be shown red before any application code.
 */
class BoxOfficeOperatorLifecycleTest extends TestCase
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

    /** AC-v2-13 — an ADMIN invites an operator: 3 rows + 1 invitation mail */
    public function test_admin_can_invite_an_operator(): void
    {
        Mail::fake();
        // createEventWithProduct()'s user is the ADMIN account owner.
        [$event, , , $admin] = $this->createEventWithProduct(price: 25.00, userPassword: self::PASSWORD);
        $token = $this->loginAndGetToken($admin, self::PASSWORD);

        $response = $this->postJson(
            "/events/{$event->id}/box-office/operators",
            ['first_name' => 'Olivia', 'last_name' => 'Op', 'email' => 'olivia.op@example.test'],
            ['Authorization' => 'Bearer ' . $token],
        );

        $response->assertStatus(201);

        $userId = DB::table('users')->where('email', 'olivia.op@example.test')->value('id');
        $this->assertNotNull($userId);
        $this->assertSame(1, DB::table('account_users')
            ->where('user_id', $userId)->where('role', 'BOX_OFFICE_OPERATOR')->where('status', 'INVITED')->count());
        $this->assertSame(1, DB::table('event_box_office_operators')
            ->where('user_id', $userId)->where('event_id', $event->id)->where('status', 'ACTIVE')->count());
        Mail::assertQueued(UserInvited::class);
    }

    /** AC-v2-14 — an ORGANIZER cannot invite an operator */
    public function test_organizer_cannot_invite_an_operator(): void
    {
        [$event, , ] = $this->createEventWithProduct(price: 25.00);
        $organizer = $this->makeOrganizerOnEvent($event, self::PASSWORD);
        $token = $this->loginAndGetToken($organizer, self::PASSWORD);

        $this->postJson(
            "/events/{$event->id}/box-office/operators",
            ['first_name' => 'Olivia', 'last_name' => 'Op', 'email' => 'olivia.op@example.test'],
            ['Authorization' => 'Bearer ' . $token],
        )->assertStatus(403);
    }

    /** AC-v2-16 — assigning an EXISTING operator to a new event: +1 row, no new user, no mail */
    public function test_existing_operator_reassigned_without_new_invitation_mail(): void
    {
        Mail::fake();
        [$eventA, , , $adminA] = $this->createEventWithProduct(price: 25.00, userPassword: self::PASSWORD);
        $operator = $this->makeBoxOfficeOperator($eventA, self::PASSWORD); // already ACTIVE on A

        // Same account, second event, invite the same email.
        [$eventB] = $this->createEventWithProductOnAccount($eventA->account_id);
        $token = $this->loginAndGetToken($adminA, self::PASSWORD);

        $operatorEmail = DB::table('users')->where('id', $operator->id)->value('email');

        $usersBefore = DB::table('users')->count();

        $this->postJson(
            "/events/{$eventB->id}/box-office/operators",
            ['first_name' => 'Olivia', 'last_name' => 'Op', 'email' => $operatorEmail],
            ['Authorization' => 'Bearer ' . $token],
        )->assertStatus(201);

        $this->assertSame($usersBefore, DB::table('users')->count(), 'no new users row for an existing operator');
        $this->assertSame(1, DB::table('event_box_office_operators')
            ->where('user_id', $operator->id)->where('event_id', $eventB->id)->count());
        Mail::assertNotQueued(UserInvited::class);
    }

    /** AC-v2-17 — revoke on A blocks A only; past sales untouched */
    public function test_revoke_blocks_target_event_only_and_keeps_past_sales(): void
    {
        [$eventA, $productA, $priceA, $admin] = $this->createEventWithProduct(price: 25.00, userPassword: self::PASSWORD);
        $this->attachCheckInList($eventA, $productA);
        [$eventB, $productB, $priceB] = $this->createEventWithProductOnAccount($eventA->account_id);
        $this->attachCheckInList($eventB, $productB);

        $operator = $this->makeBoxOfficeOperator($eventA, self::PASSWORD);
        $this->assignOperatorToEvent($operator, $eventB, status: 'ACTIVE');

        $opToken = $this->loginAndGetToken($operator, self::PASSWORD);

        // A sale on A while still active.
        $this->postJson("/events/{$eventA->id}/box-office-sales",
            $this->salePayload($productA->id, $priceA->id),
            ['Authorization' => 'Bearer ' . $opToken])->assertStatus(201);
        $salesOnABefore = DB::table('box_office_sales')->where('event_id', $eventA->id)->count();

        // ADMIN revokes on A.
        $adminToken = $this->loginAndGetToken($admin, self::PASSWORD);
        $this->patchJson("/events/{$eventA->id}/box-office/operators/{$operator->id}",
            ['status' => 'REVOKED'],
            ['Authorization' => 'Bearer ' . $adminToken])->assertStatus(200);

        // Re-auth as the operator: the JWT guard caches the last resolved user
        // within a test, and the admin PATCH above left it as the admin.
        \Illuminate\Support\Facades\Auth::logout();

        // Operator: 403 on A, still 201 on B.
        $this->postJson("/events/{$eventA->id}/box-office-sales",
            $this->salePayload($productA->id, $priceA->id),
            ['Authorization' => 'Bearer ' . $opToken])->assertStatus(403);
        $this->postJson("/events/{$eventB->id}/box-office-sales",
            $this->salePayload($productB->id, $priceB->id),
            ['Authorization' => 'Bearer ' . $opToken])->assertStatus(201);

        // Past sale on A untouched.
        $this->assertSame($salesOnABefore, DB::table('box_office_sales')->where('event_id', $eventA->id)->count());
        $this->assertSame(1, DB::table('box_office_sales')
            ->where('event_id', $eventA->id)->where('agent_user_id', $operator->id)->count());
    }

    /** AC-v2-18 — the new role exists but is never self-assignable */
    public function test_operator_role_exists_but_is_not_assignable(): void
    {
        // RED today: Role::BOX_OFFICE_OPERATOR case does not exist → \Error.
        $this->assertInstanceOf(Role::class, Role::BOX_OFFICE_OPERATOR);
        $this->assertNotContains('BOX_OFFICE_OPERATOR', Role::getAssignableRoles());
    }
}
