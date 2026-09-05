<?php

declare(strict_types=1);

namespace Tests\Feature\BoxOffice;

use HiEvents\Models\Order;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Support\BoxOfficeTestFixtures;
use Tests\TestCase;

/**
 * TDD (red) tests for the reprint endpoint (D14,
 * PICHA_BOX_OFFICE_DECISIONS_REQUIRED.md): "POST
 * /events/{event_id}/attendees/{public_id}/reprint" does not exist yet, so
 * every request below 404s today instead of behaving as asserted.
 */
class ReprintTest extends TestCase
{
    use DatabaseTransactions;
    use BoxOfficeTestFixtures;

    private const PASSWORD = 'password123!';

    /** AC-20 */
    public function test_reprint_returns_same_attendee_pdf_without_new_order(): void
    {
        [$event, $product, $productPrice, $user] = $this->createEventWithProduct(price: 25.00, userPassword: self::PASSWORD);
        $attendee = $this->createAttendeeViaHandler($event->id, $product->id, $productPrice->id);
        $ordersBefore = Order::where('event_id', $event->id)->count();

        $token = $this->loginAndGetToken($user, self::PASSWORD);

        $response = $this->getJson(
            "/events/{$event->id}/attendees/{$attendee->public_id}/reprint",
            ['Authorization' => 'Bearer ' . $token],
        );

        $response->assertStatus(200);
        self::assertSame('application/pdf', $response->headers->get('Content-Type'));
        self::assertSame($ordersBefore, Order::where('event_id', $event->id)->count(), 'AC-20: reprint must not create a new Order');
    }

    /** AC-21 */
    public function test_reprint_records_print_job(): void
    {
        [$event, $product, $productPrice, $user] = $this->createEventWithProduct(price: 25.00, userPassword: self::PASSWORD);
        $attendee = $this->createAttendeeViaHandler($event->id, $product->id, $productPrice->id);
        $token = $this->loginAndGetToken($user, self::PASSWORD);

        $this->getJson(
            "/events/{$event->id}/attendees/{$attendee->public_id}/reprint",
            ['Authorization' => 'Bearer ' . $token],
        );

        self::assertSame(
            1,
            DB::table('print_jobs')->where('attendee_id', $attendee->id)->where('agent_user_id', $user->id)->count(),
        );
    }

    /** AC-22 */
    public function test_reprint_forbidden_for_other_account(): void
    {
        [$event, $product, $productPrice] = $this->createEventWithProduct(price: 25.00);
        $attendee = $this->createAttendeeViaHandler($event->id, $product->id, $productPrice->id);

        $otherUser = $this->createUnrelatedOrganizerUser(self::PASSWORD);
        $token = $this->loginAndGetToken($otherUser, self::PASSWORD);

        $response = $this->getJson(
            "/events/{$event->id}/attendees/{$attendee->public_id}/reprint",
            ['Authorization' => 'Bearer ' . $token],
        );

        $response->assertStatus(403);
    }
}
