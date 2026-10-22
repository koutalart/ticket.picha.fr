<?php

namespace Tests\Unit\Repository;

use HiEvents\Models\Attendee;
use HiEvents\Models\Event;
use HiEvents\Models\SmsCancellationConfirmation;
use HiEvents\Models\SmsRegistrationConfirmation;
use HiEvents\Repository\Eloquent\SmsCancellationConfirmationRepository;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\BoxOfficeTestFixtures;
use Tests\TestCase;

class SmsConfirmationOnePerPhoneTest extends TestCase
{
    use BoxOfficeTestFixtures;
    use DatabaseTransactions;

    private const PHONE = '+262639250206';

    public function test_the_database_refuses_a_second_registration_confirmation_to_the_same_phone(): void
    {
        [$event, $first, $second] = $this->eventWithTwoAttendees();

        $this->registration($event, $first, 'SENT');

        $this->assertThrowsInSavepoint(fn () => $this->registration($event, $second, 'SENDING'));
    }

    public function test_skipped_and_failed_rows_do_not_take_the_phone(): void
    {
        [$event, $first, $second, $third] = $this->eventWithTwoAttendees(withThird: true);

        $this->registration($event, $first, 'FAILED');
        $this->registration($event, $second, 'SKIPPED');
        $this->registration($event, $third, 'SENT');

        $this->assertSame(3, SmsRegistrationConfirmation::where('event_id', $event->id)->count());
    }

    public function test_a_released_phone_can_be_confirmed_again(): void
    {
        [$event, $first, $second] = $this->eventWithTwoAttendees();

        $holder = $this->registration($event, $first, 'SENT');
        $holder->update(['phone_released_at' => Carbon::now()]);
        $this->registration($event, $second, 'SENT');

        $this->assertSame(2, SmsRegistrationConfirmation::where('event_id', $event->id)->where('status', 'SENT')->count());
    }

    public function test_the_same_phone_is_confirmed_for_another_event(): void
    {
        [$event, $first] = $this->eventWithTwoAttendees();
        [$otherEvent, $otherAttendee] = $this->eventWithTwoAttendees();

        $this->registration($event, $first, 'SENT');
        $this->registration($otherEvent, $otherAttendee, 'SENT');

        $this->assertSame(2, SmsRegistrationConfirmation::where('phone', self::PHONE)->count());
    }

    public function test_cancellations_in_the_same_wave_are_skipped_after_the_first(): void
    {
        [$event, $first, $second] = $this->eventWithTwoAttendees();
        $since = Carbon::now()->subMinutes(10)->toDateTimeString();

        $claimed = $this->repository()->createUnlessPhoneConfirmedSince($this->cancellation($event, $first), $since, ['status' => 'SKIPPED']);
        $skipped = $this->repository()->createUnlessPhoneConfirmedSince($this->cancellation($event, $second), $since, ['status' => 'SKIPPED']);

        $this->assertSame('SENDING', $claimed->getStatus());
        $this->assertSame('SKIPPED', $skipped->getStatus());
    }

    public function test_a_cancellation_after_the_wave_is_sent_again(): void
    {
        [$event, $first, $second] = $this->eventWithTwoAttendees();

        $old = SmsCancellationConfirmation::create([...$this->cancellation($event, $first), 'status' => 'SENT']);
        SmsCancellationConfirmation::whereKey($old->id)->update(['created_at' => Carbon::now()->subMinutes(30)]);

        $claimed = $this->repository()->createUnlessPhoneConfirmedSince(
            $this->cancellation($event, $second),
            Carbon::now()->subMinutes(10)->toDateTimeString(),
            ['status' => 'SKIPPED'],
        );

        $this->assertSame('SENDING', $claimed->getStatus());
    }

    public function test_the_same_attendee_cannot_be_claimed_twice_for_cancellation(): void
    {
        [$event, $first] = $this->eventWithTwoAttendees();
        $since = Carbon::now()->subMinutes(10)->toDateTimeString();

        $this->repository()->createUnlessPhoneConfirmedSince($this->cancellation($event, $first), $since, ['status' => 'SKIPPED']);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->repository()->createUnlessPhoneConfirmedSince($this->cancellation($event, $first), $since, ['status' => 'SKIPPED']);
    }

    private function repository(): SmsCancellationConfirmationRepository
    {
        return $this->app->make(SmsCancellationConfirmationRepository::class);
    }

    private function assertThrowsInSavepoint(callable $callback): void
    {
        try {
            DB::transaction($callback);
            $this->fail('Expected a unique constraint violation');
        } catch (UniqueConstraintViolationException $exception) {
            $this->assertStringContainsString('sms_registration_confirmations_one_per_phone', $exception->getMessage());
        }
    }

    private function eventWithTwoAttendees(bool $withThird = false): array
    {
        [$event, $product, $price] = $this->createEventWithProduct();

        $attendees = [
            $this->createAttendeeViaHandler($event->id, $product->id, $price->id),
            $this->createAttendeeViaHandler($event->id, $product->id, $price->id),
        ];
        if ($withThird) {
            $attendees[] = $this->createAttendeeViaHandler($event->id, $product->id, $price->id);
        }

        return [$event, ...$attendees];
    }

    private function registration(Event $event, Attendee $attendee, string $status): SmsRegistrationConfirmation
    {
        return SmsRegistrationConfirmation::create([
            'organizer_id' => $event->organizer_id,
            'event_id' => $event->id,
            'order_id' => $attendee->order_id,
            'attendee_id' => $attendee->id,
            'phone' => self::PHONE,
            'status' => $status,
        ]);
    }

    private function cancellation(Event $event, Attendee $attendee): array
    {
        return [
            'organizer_id' => $event->organizer_id,
            'event_id' => $event->id,
            'order_id' => $attendee->order_id,
            'attendee_id' => $attendee->id,
            'phone' => self::PHONE,
            'cancelled_by' => 'PARTICIPANT',
            'status' => 'SENDING',
        ];
    }
}
