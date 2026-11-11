<?php

namespace Tests\Unit\CheckInList;

use HiEvents\Helper\IdHelper;
use HiEvents\Models\Attendee;
use HiEvents\Models\CheckInList;
use HiEvents\Models\Event;
use HiEvents\Models\Order;
use HiEvents\Models\Product;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Testing\TestResponse;
use Tests\Support\BoxOfficeTestFixtures;
use Tests\TestCase;

class GetCheckInListAttendeePublicTest extends TestCase
{
    use BoxOfficeTestFixtures;
    use DatabaseTransactions;

    private Event $event;

    private Product $product;

    private int $productPriceId;

    private CheckInList $checkInList;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->event, $this->product, $productPrice] = $this->createEventWithProduct();
        $this->productPriceId = $productPrice->id;
        $this->checkInList = $this->attachCheckInList($this->event, $this->product);
    }

    public function test_active_attendee_is_returned(): void
    {
        $attendee = $this->attendee('ACTIVE');

        $this->fetch($this->checkInList->short_id, $attendee->public_id)
            ->assertOk()
            ->assertJsonPath('data.public_id', $attendee->public_id)
            ->assertJsonPath('data.status', 'ACTIVE');
    }

    public function test_cancelled_attendee_is_returned_with_cancelled_status(): void
    {
        $attendee = $this->attendee('CANCELLED');

        $this->fetch($this->checkInList->short_id, $attendee->public_id)
            ->assertOk()
            ->assertJsonPath('data.status', 'CANCELLED');
    }

    public function test_unknown_attendee_returns_not_found(): void
    {
        $this->fetch($this->checkInList->short_id, 'A-UNKNOWN123')
            ->assertNotFound()
            ->assertJsonStructure(['message']);
    }

    public function test_attendee_from_another_event_returns_not_found(): void
    {
        [$otherEvent, $otherProduct] = $this->createEventWithProduct();
        $otherList = $this->attachCheckInList($otherEvent, $otherProduct);

        $this->fetch($otherList->short_id, $this->attendee('ACTIVE')->public_id)
            ->assertNotFound();
    }

    public function test_unknown_check_in_list_returns_not_found(): void
    {
        $this->fetch('cil_unknown', $this->attendee('ACTIVE')->public_id)
            ->assertNotFound();
    }

    public function test_expired_check_in_list_returns_forbidden(): void
    {
        $this->checkInList->update(['expires_at' => now()->subDay()]);

        $this->fetch($this->checkInList->short_id, $this->attendee('ACTIVE')->public_id)
            ->assertForbidden();
    }

    private function fetch(string $shortId, string $attendeePublicId): TestResponse
    {
        return $this->getJson("/public/check-in-lists/{$shortId}/attendees/{$attendeePublicId}");
    }

    private function attendee(string $status): Attendee
    {
        $order = Order::create([
            'event_id' => $this->event->id,
            'total_gross' => 0,
            'total_refunded' => 0,
            'currency' => 'EUR',
            'status' => 'COMPLETED',
            'payment_status' => 'PAYMENT_RECEIVED',
            'payment_provider' => 'STRIPE',
            'email' => 'nadia@example.test',
            'first_name' => 'Nadia',
            'last_name' => 'Test',
            'short_id' => IdHelper::shortId(IdHelper::ORDER_PREFIX),
            'public_id' => IdHelper::publicId(IdHelper::ORDER_PREFIX),
        ]);

        return Attendee::create([
            'event_id' => $this->event->id,
            'product_id' => $this->product->id,
            'product_price_id' => $this->productPriceId,
            'order_id' => $order->id,
            'status' => $status,
            'email' => 'nadia@example.test',
            'first_name' => 'Nadia',
            'last_name' => 'Test',
            'short_id' => IdHelper::shortId(IdHelper::ATTENDEE_PREFIX),
            'public_id' => IdHelper::publicId(IdHelper::ATTENDEE_PREFIX),
        ]);
    }
}
