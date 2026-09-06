<?php

declare(strict_types=1);

namespace Tests\Unit\BoxOffice;

use HiEvents\DomainObjects\Enums\Role;
use HiEvents\Exceptions\UnauthorizedException;
use HiEvents\Models\Event;
use HiEvents\Services\Infrastructure\Authorization\IsAuthorizedService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Support\BoxOfficeTestFixtures;
use Tests\TestCase;

/**
 * TDD (red) — Kiosk v2, D23 Option 1 (PICHA_KIOSK_V2_DECISIONS.md §D23 / parcours v2.1 §C.5).
 *
 * Nothing exists yet:
 *   - the `Role::BOX_OFFICE_OPERATOR` enum case,
 *   - the negative guard in `IsAuthorizedService::validateUserRole()`,
 *   - the `IsAuthorizedService::validateBoxOfficeEventScope()` method,
 *   - the `event_box_office_operators` table.
 *
 * Every test below is expected to fail or error until the slice v2.1
 * implementation lands. This file MUST be written and shown red before any
 * application code (same discipline as slice 1, FIRST_SLICE §5.2).
 */
class BoxOfficeOperatorAuthorizationTest extends TestCase
{
    use DatabaseTransactions;
    use BoxOfficeTestFixtures;

    private const PASSWORD = 'password123!';

    private function service(): IsAuthorizedService
    {
        return app(IsAuthorizedService::class);
    }

    /**
     * AC: the negative guard. An operator hitting anything with the default
     * ORGANIZER floor must be rejected outright.
     * RED today: validateUserRole() has no guard for this role → no throw.
     */
    public function test_operator_is_rejected_by_validate_user_role_at_the_organizer_floor(): void
    {
        [$event, , , $admin] = $this->createEventWithProduct(price: 25.00);
        $operator = $this->makeBoxOfficeOperator($event, self::PASSWORD);
        $authUser = $this->hydrateUserForAccount($operator, $event->account_id);

        $this->expectException(UnauthorizedException::class);

        $this->service()->validateUserRole(Role::ORGANIZER, $authUser);
    }

    /**
     * AC: the operator must still pass its OWN floor.
     * RED today: `Role::BOX_OFFICE_OPERATOR` case does not exist → \Error.
     */
    public function test_operator_passes_validate_user_role_at_the_box_office_floor(): void
    {
        [$event, , , ] = $this->createEventWithProduct(price: 25.00);
        $operator = $this->makeBoxOfficeOperator($event, self::PASSWORD);
        $authUser = $this->hydrateUserForAccount($operator, $event->account_id);

        $this->service()->validateUserRole(Role::BOX_OFFICE_OPERATOR, $authUser);

        $this->assertTrue(true, 'validateUserRole must not throw for an operator at its own floor');
    }

    /**
     * AC: a plain ORGANIZER is untouched by the new guard (non-regression),
     * asserted alongside the operator being blocked so the test is red today.
     * RED today: the operator half does not throw.
     */
    public function test_organizer_unaffected_while_operator_blocked(): void
    {
        [$event, , , ] = $this->createEventWithProduct(price: 25.00);

        $organizer = $this->makeOrganizerOnEvent($event, self::PASSWORD);
        $organizerAuth = $this->hydrateUserForAccount($organizer, $event->account_id);

        // ORGANIZER: must NOT throw.
        $this->service()->validateUserRole(Role::ORGANIZER, $organizerAuth);

        // OPERATOR: must throw.
        $operator = $this->makeBoxOfficeOperator($event, self::PASSWORD);
        $operatorAuth = $this->hydrateUserForAccount($operator, $event->account_id);

        $threw = false;
        try {
            $this->service()->validateUserRole(Role::ORGANIZER, $operatorAuth);
        } catch (UnauthorizedException) {
            $threw = true;
        }

        $this->assertTrue($threw, 'operator must be rejected at the ORGANIZER floor');
    }

    /**
     * AC-v2-*: validateBoxOfficeEventScope() passes for an ACTIVE assignment.
     * RED today: method does not exist → \Error.
     */
    public function test_event_scope_passes_for_an_active_assignment(): void
    {
        [$event, , , ] = $this->createEventWithProduct(price: 25.00);
        $operator = $this->makeBoxOfficeOperator($event, self::PASSWORD, status: 'ACTIVE');
        $authUser = $this->hydrateUserForAccount($operator, $event->account_id);

        $this->service()->validateBoxOfficeEventScope($event->id, $authUser);

        $this->assertTrue(true, 'an ACTIVE assignment must pass the scope check');
    }

    /**
     * AC-v2-2: rejected for an event the operator is not assigned to.
     * RED today: method does not exist → \Error.
     */
    public function test_event_scope_rejects_an_unassigned_event(): void
    {
        [$eventA, , , ] = $this->createEventWithProduct(price: 25.00);
        [$eventB, , , ] = $this->createEventWithProduct(price: 25.00);
        $operator = $this->makeBoxOfficeOperator($eventA, self::PASSWORD);
        $authUser = $this->hydrateUserForAccount($operator, $eventA->account_id);

        $this->expectException(UnauthorizedException::class);

        $this->service()->validateBoxOfficeEventScope($eventB->id, $authUser);
    }

    /**
     * AC-v2-17: a REVOKED assignment no longer passes the scope check.
     * RED today: method does not exist → \Error.
     */
    public function test_event_scope_rejects_a_revoked_assignment(): void
    {
        [$event, , , ] = $this->createEventWithProduct(price: 25.00);
        $operator = $this->makeBoxOfficeOperator($event, self::PASSWORD, status: 'REVOKED');
        $authUser = $this->hydrateUserForAccount($operator, $event->account_id);

        $this->expectException(UnauthorizedException::class);

        $this->service()->validateBoxOfficeEventScope($event->id, $authUser);
    }

    /**
     * AC-v2-7: an ORGANIZER is a no-op for the scope check (may use the box
     * office for any event of their account), asserted with the operator
     * rejection so the test is red today.
     * RED today: method does not exist → \Error.
     */
    public function test_event_scope_is_a_noop_for_organizer(): void
    {
        [$event, , , ] = $this->createEventWithProduct(price: 25.00);
        $organizer = $this->makeOrganizerOnEvent($event, self::PASSWORD);
        $authUser = $this->hydrateUserForAccount($organizer, $event->account_id);

        $this->service()->validateBoxOfficeEventScope($event->id, $authUser);

        $this->assertTrue(true, 'ORGANIZER must pass the scope check unconditionally');
    }
}
