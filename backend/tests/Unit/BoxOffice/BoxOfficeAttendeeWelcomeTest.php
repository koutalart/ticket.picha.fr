<?php

declare(strict_types=1);

namespace Tests\Unit\BoxOffice;

use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Models\Attendee;
use HiEvents\Services\Application\Handlers\BoxOffice\BoxOfficeCheckInAttendeeHandler;
use HiEvents\Services\Application\Handlers\BoxOffice\DTO\BoxOfficeCheckInResultDTO;
use HiEvents\Services\Application\Handlers\BoxOffice\SearchBoxOfficeAttendeesHandler;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Support\BoxOfficeTestFixtures;
use Tests\TestCase;

class BoxOfficeAttendeeWelcomeTest extends TestCase
{
    use BoxOfficeTestFixtures;
    use DatabaseTransactions;

    private function search(int $eventId, string $query): array
    {
        return app(SearchBoxOfficeAttendeesHandler::class)->handle($eventId, $query)->all();
    }

    private function checkIn(int $eventId, string $publicId): BoxOfficeCheckInResultDTO
    {
        return app(BoxOfficeCheckInAttendeeHandler::class)->handle($eventId, $publicId, '127.0.0.1');
    }

    private function registeredAttendee(string $firstName = 'Amina', string $lastName = 'Conférence'): array
    {
        [$event, $product, $productPrice] = $this->createEventWithProduct(price: 0.00);
        $this->attachCheckInList($event, $product);
        $attendee = $this->createAttendeeViaHandler($event->id, $product->id, $productPrice->id);
        Attendee::whereKey($attendee->id)->update(['first_name' => $firstName, 'last_name' => $lastName, 'email' => 'amina@example.invalid']);

        return [$event, $product, $attendee->fresh()];
    }

    public function test_search_finds_an_attendee_by_name_email_or_ticket_number(): void
    {
        [$event, $product, $attendee] = $this->registeredAttendee();

        foreach (['conférence', 'Amina Conf', 'amina@example', $attendee->public_id] as $query) {
            $results = $this->search($event->id, $query);
            self::assertCount(1, $results, "query: $query");
            self::assertSame($attendee->public_id, $results[0]->public_id);
            self::assertSame($product->title, $results[0]->product_title);
            self::assertNull($results[0]->checked_in_at);
        }
    }

    public function test_search_is_scoped_to_the_event(): void
    {
        [$event] = $this->registeredAttendee('Amina');
        [$otherEvent] = $this->registeredAttendee('Amina');

        self::assertCount(1, $this->search($event->id, 'Amina'));
        self::assertCount(1, $this->search($otherEvent->id, 'Amina'));
    }

    public function test_a_blank_query_returns_nothing(): void
    {
        [$event] = $this->registeredAttendee();

        self::assertSame([], $this->search($event->id, '  '));
    }

    public function test_check_in_records_the_entry_once(): void
    {
        [$event, , $attendee] = $this->registeredAttendee();

        $first = $this->checkIn($event->id, $attendee->public_id);
        self::assertSame(BoxOfficeCheckInResultDTO::CHECKED_IN, $first->status);
        self::assertNotNull($first->checked_in_at);

        $second = $this->checkIn($event->id, $attendee->public_id);
        self::assertSame(BoxOfficeCheckInResultDTO::ALREADY_CHECKED_IN, $second->status);
        self::assertSame($first->checked_in_at, $second->checked_in_at);

        self::assertSame(1, DB::table('attendee_check_ins')->where('attendee_id', $attendee->id)->count());
        self::assertNotNull($this->search($event->id, $attendee->public_id)[0]->checked_in_at);
    }

    public function test_a_cancelled_ticket_is_refused(): void
    {
        [$event, , $attendee] = $this->registeredAttendee();
        Attendee::whereKey($attendee->id)->update(['status' => 'CANCELLED']);

        $result = $this->checkIn($event->id, $attendee->public_id);

        self::assertSame(BoxOfficeCheckInResultDTO::REFUSED, $result->status);
        self::assertNotEmpty($result->message);
        self::assertSame(0, DB::table('attendee_check_ins')->where('attendee_id', $attendee->id)->count());
    }

    public function test_an_attendee_of_another_event_is_not_found(): void
    {
        [$event] = $this->registeredAttendee();
        [, , $foreignAttendee] = $this->registeredAttendee();

        $this->expectException(ResourceNotFoundException::class);

        $this->checkIn($event->id, $foreignAttendee->public_id);
    }
}
