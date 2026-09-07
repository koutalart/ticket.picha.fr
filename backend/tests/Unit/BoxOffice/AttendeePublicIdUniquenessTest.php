<?php

declare(strict_types=1);

namespace Tests\Unit\BoxOffice;

use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Services\Domain\Attendee\AttendeePublicIdGenerator;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Support\BoxOfficeTestFixtures;
use Tests\TestCase;

/**
 * AC-16 (S4, PICHA_BOX_OFFICE_SECURITY_FINDINGS.md): attendees.public_id
 * must be unique at the DB level, and the generator must retry on collision.
 */
class AttendeePublicIdUniquenessTest extends TestCase
{
    use BoxOfficeTestFixtures;
    use DatabaseTransactions;

    /**
     * This queries the REAL, current schema — no new code involved. It is
     * expected to be genuinely red today: confirmed by direct inspection,
     * attendees.public_id currently has only a trigram GIN index and a
     * lowercase functional index, neither of which is UNIQUE (only
     * orders.public_id has a unique constraint today).
     */
    public function test_public_id_has_unique_index(): void
    {
        $indexes = DB::select("SELECT indexdef FROM pg_indexes WHERE tablename = 'attendees'");

        $hasUniquePublicIdIndex = collect($indexes)->contains(
            fn ($index) => str_contains($index->indexdef, 'UNIQUE') && str_contains($index->indexdef, 'public_id')
        );

        self::assertTrue($hasUniquePublicIdIndex, 'S4: attendees.public_id has no unique index yet');
    }

    /**
     * AttendeePublicIdGenerator does not exist yet. Once introduced (to
     * replace CreateAttendeeHandler's direct, non-retrying
     * IdHelper::publicId() call), it must retry when a generated candidate
     * collides with an existing row.
     */
    public function test_generator_retries_on_collision(): void
    {
        [$event, $product, $productPrice] = $this->createEventWithProduct(price: 25.00);

        $order = \HiEvents\Models\Order::create([
            'event_id' => $event->id,
            'total_gross' => 25.00,
            'currency' => 'USD',
            'status' => 'COMPLETED',
            'short_id' => \HiEvents\Helper\IdHelper::shortId(\HiEvents\Helper\IdHelper::ORDER_PREFIX),
            'public_id' => \HiEvents\Helper\IdHelper::publicId(\HiEvents\Helper\IdHelper::ORDER_PREFIX),
        ]);

        // An existing attendee already holds the public_id the mocked
        // generator will produce on its first attempt.
        \HiEvents\Models\Attendee::create([
            'event_id' => $event->id,
            'product_id' => $product->id,
            'product_price_id' => $productPrice->id,
            'order_id' => $order->id,
            'status' => 'ACTIVE',
            'email' => 'existing@example.test',
            'first_name' => 'Existing',
            'last_name' => 'Attendee',
            'short_id' => \HiEvents\Helper\IdHelper::shortId(\HiEvents\Helper\IdHelper::ATTENDEE_PREFIX),
            'public_id' => 'COLLIDE1',
        ]);

        $attempts = 0;
        $candidates = ['COLLIDE1', 'COLLIDE1', 'UNIQUE2'];

        $generator = new AttendeePublicIdGenerator(
            app(AttendeeRepositoryInterface::class),
            candidateFactory: function () use (&$attempts, $candidates) {
                return $candidates[$attempts++];
            },
        );

        $result = $generator->generateUnique();

        self::assertSame('UNIQUE2', $result);
        self::assertSame(3, $attempts, 'the generator must retry past both colliding candidates');
    }
}
