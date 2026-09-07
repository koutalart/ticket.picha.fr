<?php

declare(strict_types=1);

namespace Tests\Feature\BoxOffice;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Support\BoxOfficeTestFixtures;
use Tests\TestCase;

/**
 * TDD (red) end-to-end test for the full slice 1 path: sell → fetch PDF →
 * first scan → second scan. "POST /events/{event_id}/box-office-sales"
 * does not exist yet, so this 404s at the very first step today.
 */
class BoxOfficeEndToEndTest extends TestCase
{
    use BoxOfficeTestFixtures;
    use DatabaseTransactions;

    private const PASSWORD = 'password123!';

    /** AC-14, AC-23, AC-24 */
    public function test_full_slice_sale_then_pdf_then_first_scan_then_second_scan(): void
    {
        [$event, $product, $productPrice, $user] = $this->createEventWithProduct(price: 25.00, userPassword: self::PASSWORD);
        $checkInList = $this->attachCheckInList($event, $product);
        $token = $this->loginAndGetToken($user, self::PASSWORD);

        // 1. Sell.
        $saleResponse = $this->postJson(
            "/events/{$event->id}/box-office-sales",
            [
                'product_id' => $product->id,
                'product_price_id' => $productPrice->id,
                'phone' => '+33612345678',
                'first_name' => 'Jane',
                'last_name' => 'Doe',
                'email' => 'jane@example.test',
                'locale' => 'en',
                'payment_method' => 'CASH',
                'amount' => 25.00,
                'amount_collected' => 25.00,
                'send_confirmation_email' => false,
                'idempotency_key' => \Illuminate\Support\Str::uuid()->toString(),
            ],
            ['Authorization' => 'Bearer '.$token],
        );
        $saleResponse->assertStatus(201);
        $publicId = $saleResponse->json('data.attendee.public_id');

        // 2. Fetch the PDF.
        $pdfResponse = $this->getJson(
            "/events/{$event->id}/attendees/{$publicId}/ticket.pdf",
            ['Authorization' => 'Bearer '.$token],
        );
        $pdfResponse->assertStatus(200);
        self::assertSame('application/pdf', $pdfResponse->headers->get('Content-Type'));

        // 3. First scan — accepted. (Public, unauthenticated endpoint.)
        \Illuminate\Support\Facades\Auth::logout();
        $checkInPayload = ['attendees' => [['public_id' => $publicId, 'action' => 'check-in']]];
        $first = $this->postJson("/public/check-in-lists/{$checkInList->short_id}/check-ins", $checkInPayload);
        $first->assertStatus(200);
        self::assertSame([], array_filter($first->json('errors') ?? []));

        // 4. Second scan — rejected, "already checked in".
        $second = $this->postJson("/public/check-in-lists/{$checkInList->short_id}/check-ins", $checkInPayload);
        $second->assertStatus(200);
        self::assertNotEmpty($second->json("errors.{$publicId}"));
    }
}
