<?php

declare(strict_types=1);

namespace Tests\Feature\BoxOffice;

use HiEvents\Mail\Attendee\AttendeeTicketMail;
use HiEvents\Models\Attendee;
use HiEvents\Models\BoxOfficeSale;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Support\BoxOfficeTestFixtures;
use Tests\TestCase;

class BoxOfficeSaleNoEmailTest extends TestCase
{
    use BoxOfficeTestFixtures;
    use DatabaseTransactions;

    private const PASSWORD = 'password123!';

    /** @return array{0: \HiEvents\Models\Event, 1: \HiEvents\Models\Product, 2: \HiEvents\Models\ProductPrice, 3: string, 4: \HiEvents\Models\CheckInList} */
    private function authenticatedEvent(): array
    {
        [$event, $product, $productPrice, $user] = $this->createEventWithProduct(price: 25.00, userPassword: self::PASSWORD);
        $checkInList = $this->attachCheckInList($event, $product);
        $token = $this->loginAndGetToken($user, self::PASSWORD);

        return [$event, $product, $productPrice, $token, $checkInList];
    }

    public function test_phone_and_name_sale_stores_null_email_pdf_and_check_in_work(): void
    {
        [$event, $product, $productPrice, $token, $checkInList] = $this->authenticatedEvent();

        $response = $this->postJson(
            "/events/{$event->id}/box-office-sales",
            [
                'product_id' => $product->id,
                'product_price_id' => $productPrice->id,
                'phone' => '+33 6 12 34 56 78',
                'first_name' => 'Jane',
                'locale' => 'fr',
                'payment_method' => 'CASH',
                'amount' => 25.00,
                'amount_collected' => 25.00,
                'idempotency_key' => Str::uuid()->toString(),
            ],
            ['Authorization' => 'Bearer '.$token],
        );

        $response->assertCreated();

        $sale = BoxOfficeSale::query()->where('event_id', $event->id)->first();
        self::assertSame('+33612345678', $sale?->phone);

        $attendee = Attendee::query()->find($sale->attendee_id);
        self::assertNull($attendee->email);
        self::assertSame('Jane', $attendee->first_name);

        $pdf = $this->get(
            "/events/{$event->id}/attendees/{$attendee->public_id}/ticket.pdf",
            ['Authorization' => 'Bearer '.$token],
        );
        $pdf->assertOk();
        self::assertSame('application/pdf', $pdf->headers->get('Content-Type'));

        Auth::logout();
        $checkIn = $this->postJson(
            "/public/check-in-lists/{$checkInList->short_id}/check-ins",
            ['attendees' => [['public_id' => $attendee->public_id, 'action' => 'check-in']]],
        );
        $checkIn->assertOk();
        self::assertSame([], array_filter($checkIn->json('errors') ?? []));
    }

    public function test_resend_ticket_without_email_is_422_and_queues_nothing(): void
    {
        [$event, $product, $productPrice, $token] = $this->authenticatedEvent();

        $sale = $this->postJson(
            "/events/{$event->id}/box-office-sales",
            [
                'product_id' => $product->id,
                'product_price_id' => $productPrice->id,
                'phone' => '+33612345678',
                'first_name' => 'Jane',
                'locale' => 'fr',
                'payment_method' => 'CASH',
                'amount' => 25.00,
                'amount_collected' => 25.00,
                'idempotency_key' => Str::uuid()->toString(),
            ],
            ['Authorization' => 'Bearer '.$token],
        );
        $sale->assertCreated();
        $publicId = $sale->json('data.attendee.public_id');

        Mail::fake();

        $resend = $this->postJson(
            "/events/{$event->id}/attendees/{$publicId}/resend-ticket",
            [],
            ['Authorization' => 'Bearer '.$token],
        );

        $resend->assertUnprocessable();
        $resend->assertJsonFragment([
            'message' => __('Ce participant n\'a pas encore d\'e-mail. Renseignez-le d\'abord.'),
        ]);
        Mail::assertNothingQueued();
        Mail::assertNothingSent();
    }

    public function test_admin_can_fill_email_then_resend_ticket(): void
    {
        [$event, $product, $productPrice, $token] = $this->authenticatedEvent();

        $sale = $this->postJson(
            "/events/{$event->id}/box-office-sales",
            [
                'product_id' => $product->id,
                'product_price_id' => $productPrice->id,
                'phone' => '+33612345678',
                'first_name' => 'Jane',
                'locale' => 'fr',
                'payment_method' => 'CASH',
                'amount' => 25.00,
                'amount_collected' => 25.00,
                'idempotency_key' => Str::uuid()->toString(),
            ],
            ['Authorization' => 'Bearer '.$token],
        );
        $sale->assertCreated();

        $attendeeId = $sale->json('data.attendee.id');
        $publicId = $sale->json('data.attendee.public_id');

        $patch = $this->patchJson(
            "/events/{$event->id}/attendees/{$attendeeId}",
            ['email' => 'jane.completed@example.test'],
            ['Authorization' => 'Bearer '.$token],
        );
        $patch->assertOk();
        self::assertSame('jane.completed@example.test', Attendee::query()->find($attendeeId)?->email);

        Mail::fake();

        $resend = $this->postJson(
            "/events/{$event->id}/attendees/{$publicId}/resend-ticket",
            [],
            ['Authorization' => 'Bearer '.$token],
        );
        $resend->assertSuccessful();
        Mail::assertQueued(AttendeeTicketMail::class, 1);
    }

    public function test_sale_without_name_email_or_phone_is_rejected(): void
    {
        [$event, $product, $productPrice, $token] = $this->authenticatedEvent();

        $response = $this->postJson(
            "/events/{$event->id}/box-office-sales",
            [
                'product_id' => $product->id,
                'product_price_id' => $productPrice->id,
                'locale' => 'fr',
                'payment_method' => 'CASH',
                'amount' => 25.00,
                'amount_collected' => 25.00,
                'idempotency_key' => Str::uuid()->toString(),
            ],
            ['Authorization' => 'Bearer '.$token],
        );

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['email']);
    }
}
