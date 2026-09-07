<?php

declare(strict_types=1);

namespace Tests\Feature\BoxOffice;

use HiEvents\Helper\IdHelper;
use HiEvents\Models\Attendee;
use HiEvents\Repository\Eloquent\AttendeeCheckInRepository;
use HiEvents\Repository\Interfaces\AttendeeCheckInRepositoryInterface;
use HiEvents\Services\Application\Handlers\Attendee\CreateAttendeeHandler;
use HiEvents\Services\Application\Handlers\Attendee\DTO\CreateAttendeeDTO;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\Support\BoxOfficeTestFixtures;
use Tests\TestCase;

/**
 * Verifies that an attendee created through the existing manual-sale path
 * (CreateAttendeeHandler — what the Box Office slice 1, Option A, intends
 * to reuse) is fully compatible with the native, unmodified public check-in
 * endpoint. Also documents S5 (double check-in race → HTTP 500) and D11
 * (product not on any active check-in list → rejected).
 *
 * See PICHA_BOX_OFFICE_CHECKIN_MATRIX.md §6.
 */
class NativeCheckInCompatibilityTest extends TestCase
{
    use BoxOfficeTestFixtures;
    use DatabaseTransactions;

    private function createAttendee(int $eventId, int $productId, int $productPriceId): Attendee
    {
        $handler = app(CreateAttendeeHandler::class);

        $domainAttendee = $handler->handle(new CreateAttendeeDTO(
            first_name: 'Jane',
            last_name: 'Doe',
            email: 'jane@example.test',
            product_id: $productId,
            event_id: $eventId,
            send_confirmation_email: false,
            amount_paid: 25.00,
            locale: 'en',
            product_price_id: $productPriceId,
        ));

        return Attendee::find($domainAttendee->getId());
    }

    public function test_manually_created_attendee_is_accepted_on_first_scan(): void
    {
        [$event, $product, $productPrice] = $this->createEventWithProduct(price: 25.00);
        $checkInList = $this->attachCheckInList($event, $product);
        $attendee = $this->createAttendee($event->id, $product->id, $productPrice->id);

        // createEventWithProduct() calls Auth::login() (required by
        // Event::boot()) — logging out avoids SetUserLocaleMiddleware
        // picking up that user's (random, factory-generated) locale on this
        // otherwise-unauthenticated public request.
        \Illuminate\Support\Facades\Auth::logout();

        $response = $this->postJson(
            "/public/check-in-lists/{$checkInList->short_id}/check-ins",
            ['attendees' => [['public_id' => $attendee->public_id, 'action' => 'check-in']]],
        );

        $response->assertStatus(200);
        self::assertSame(
            1,
            DB::table('attendee_check_ins')
                ->where('attendee_id', $attendee->id)
                ->where('check_in_list_id', $checkInList->id)
                ->count(),
        );
        self::assertEmpty($response->json('errors'));
    }

    public function test_second_sequential_scan_is_rejected_without_duplicate_row(): void
    {
        [$event, $product, $productPrice] = $this->createEventWithProduct(price: 25.00);
        $checkInList = $this->attachCheckInList($event, $product);
        $attendee = $this->createAttendee($event->id, $product->id, $productPrice->id);

        \Illuminate\Support\Facades\Auth::logout();

        $url = "/public/check-in-lists/{$checkInList->short_id}/check-ins";
        $payload = ['attendees' => [['public_id' => $attendee->public_id, 'action' => 'check-in']]];

        $first = $this->postJson($url, $payload);
        $first->assertStatus(200);

        $second = $this->postJson($url, $payload);
        $second->assertStatus(200);

        $errors = $second->json('errors');
        self::assertArrayHasKey($attendee->public_id, $errors);
        self::assertNotEmpty($errors[$attendee->public_id], 'a rejection message must be present (wording is locale-dependent)');

        self::assertSame(
            1,
            DB::table('attendee_check_ins')
                ->where('attendee_id', $attendee->id)
                ->where('check_in_list_id', $checkInList->id)
                ->count(),
            'a duplicate row must not be created by the rejected replay',
        );
    }

    /**
     * Documents S5 (PICHA_BOX_OFFICE_SECURITY_FINDINGS.md): two concurrent
     * check-ins for the same attendee both pass the "not already checked in"
     * pre-check (CreateAttendeeCheckInService::processIndividualCheckIn()
     * calls getExistingCheckIn() before opening its DB transaction), then
     * both attempt to INSERT. The unique index added by
     * modules/digit/database/migrations/2026_07_18_000001_add_unique_constraint_to_attendee_check_ins.php
     * stops the duplicate row, but the resulting Postgres unique-violation
     * is never caught (only CannotCheckInException is caught in
     * CreateAttendeeCheckInPublicAction) — it surfaces as an uncaught
     * exception, i.e. HTTP 500, not a graceful "already checked in" error.
     *
     * True concurrency can't be produced by a synchronous, single-threaded
     * HTTP test client, so the race is reproduced deterministically: a
     * decorated AttendeeCheckInRepositoryInterface inserts a competing
     * check-in row (raw SQL, bypassing the service) exactly at the moment
     * the real request performs its own pre-check — i.e., strictly between
     * "no existing check-in found" and this request's own insert, which is
     * the exact window the race exploits.
     */
    public function test_second_concurrent_scan_returns_500(): void
    {
        [$event, $product, $productPrice] = $this->createEventWithProduct(price: 25.00);
        $checkInList = $this->attachCheckInList($event, $product);
        $attendee = $this->createAttendee($event->id, $product->id, $productPrice->id);

        \Illuminate\Support\Facades\Auth::logout();

        $this->app->bind(
            AttendeeCheckInRepositoryInterface::class,
            fn (Application $app) => new class($app, $app->make(DatabaseManager::class), $attendee->id, $checkInList->id, $product->id, $event->id) extends AttendeeCheckInRepository
            {
                public function __construct(
                    Application $application,
                    DatabaseManager $db,
                    private readonly int $raceAttendeeId,
                    private readonly int $raceCheckInListId,
                    private readonly int $raceProductId,
                    private readonly int $raceEventId,
                ) {
                    parent::__construct($application, $db);
                }

                public function findWhereIn(
                    string $field,
                    array $values,
                    array $additionalWhere = [],
                    array $columns = ['*'],
                ): Collection {
                    $result = parent::findWhereIn($field, $values, $additionalWhere, $columns);

                    // The "winning" concurrent request commits its check-in
                    // right here — after our pre-check ran (this call), but
                    // before our own insert below.
                    DB::table('attendee_check_ins')->insert([
                        'short_id' => IdHelper::shortId(IdHelper::CHECK_IN_PREFIX),
                        'check_in_list_id' => $this->raceCheckInListId,
                        'product_id' => $this->raceProductId,
                        'attendee_id' => $this->raceAttendeeId,
                        'event_id' => $this->raceEventId,
                        'ip_address' => '127.0.0.1',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    return $result;
                }
            },
        );

        $response = $this->postJson(
            "/public/check-in-lists/{$checkInList->short_id}/check-ins",
            ['attendees' => [['public_id' => $attendee->public_id, 'action' => 'check-in']]],
        );

        // This asserts the DESIRED behavior — a concurrent check-in race
        // should surface as a graceful business error, not a raw server
        // error — and is expected to be RED today: the resulting Postgres
        // unique-violation is never caught (only CannotCheckInException is
        // caught in CreateAttendeeCheckInPublicAction), so it surfaces as a
        // plain 500. The failure itself is the proof of S5.
        self::assertNotSame(
            500,
            $response->getStatusCode(),
            'S5: a concurrent check-in race must be handled gracefully, but the unique-violation is uncaught today',
        );
    }

    public function test_attendee_whose_product_not_on_list_is_rejected(): void
    {
        [$event, $product, $productPrice] = $this->createEventWithProduct(price: 25.00);

        // A check-in list exists for the event, but the product is NOT
        // attached to it (D11: not scannable).
        $checkInList = \HiEvents\Models\CheckInList::create([
            'event_id' => $event->id,
            'short_id' => IdHelper::shortId(IdHelper::CHECK_IN_LIST_PREFIX),
            'name' => 'Unrelated list',
        ]);

        $attendee = $this->createAttendee($event->id, $product->id, $productPrice->id);

        \Illuminate\Support\Facades\Auth::logout();

        $response = $this->postJson(
            "/public/check-in-lists/{$checkInList->short_id}/check-ins",
            ['attendees' => [['public_id' => $attendee->public_id, 'action' => 'check-in']]],
        );

        $response->assertStatus(409);
        self::assertSame(
            0,
            DB::table('attendee_check_ins')
                ->where('attendee_id', $attendee->id)
                ->where('check_in_list_id', $checkInList->id)
                ->count(),
        );
    }
}
