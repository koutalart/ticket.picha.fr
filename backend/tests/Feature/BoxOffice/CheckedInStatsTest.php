<?php

declare(strict_types=1);

namespace Tests\Feature\BoxOffice;

use HiEvents\Services\Application\Handlers\Attendee\CreateAttendeeHandler;
use HiEvents\Services\Application\Handlers\Attendee\DTO\CreateAttendeeDTO;
use HiEvents\Services\Domain\Event\EventStatsFetchService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Tests\Support\BoxOfficeTestFixtures;
use Tests\TestCase;

class CheckedInStatsTest extends TestCase
{
    use BoxOfficeTestFixtures;
    use DatabaseTransactions;

    public function test_scanned_attendees_are_counted_as_entered(): void
    {
        [$event, $product, $productPrice] = $this->createEventWithProduct(price: 25.00);
        $checkInList = $this->attachCheckInList($event, $product);

        $attendees = collect(['Jane', 'John'])->map(fn (string $name) => app(CreateAttendeeHandler::class)->handle(new CreateAttendeeDTO(
            first_name: $name,
            last_name: 'Doe',
            email: strtolower($name).'@example.test',
            product_id: $product->id,
            event_id: $event->id,
            send_confirmation_email: false,
            locale: 'en',
            product_price_id: $productPrice->id,
        )));

        Auth::logout();

        $this->postJson(
            "/public/check-in-lists/{$checkInList->short_id}/check-ins",
            ['attendees' => [['public_id' => $attendees->first()->getPublicId(), 'action' => 'check-in']]],
        )->assertOk();

        $stats = app(EventStatsFetchService::class)->getCheckedInStats($event->id);

        self::assertSame(1, (int) $stats->total_checked_in_attendees);
        self::assertSame(2, (int) $stats->total_attendees);
    }
}
