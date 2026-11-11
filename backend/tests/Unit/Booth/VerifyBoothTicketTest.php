<?php

namespace Tests\Unit\Booth;

use HiEvents\Helper\IdHelper;
use HiEvents\Models\Attendee;
use HiEvents\Models\Event;
use HiEvents\Models\Order;
use HiEvents\Models\Product;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\BoxOfficeTestFixtures;
use Tests\TestCase;

class VerifyBoothTicketTest extends TestCase
{
    use BoxOfficeTestFixtures;
    use DatabaseTransactions;

    private const KEY = 'test-booth-key-0123456789';

    private Event $event;

    private Product $product;

    private int $productPriceId;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.booth_api_key' => self::KEY]);
        [$this->event, $this->product, $productPrice] = $this->createEventWithProduct();
        $this->productPriceId = $productPrice->id;
    }

    public function test_active_ticket_is_valid(): void
    {
        $attendee = $this->attendee('ACTIVE');

        $this->verify($this->event->id, $attendee->public_id)
            ->assertOk()
            ->assertExactJson([
                'valid' => true,
                'status' => 'ACTIVE',
                'event_id' => $this->event->id,
                'product_id' => $this->product->id,
            ]);
    }

    public function test_cancelled_ticket_is_not_valid(): void
    {
        $attendee = $this->attendee('CANCELLED');

        $this->verify($this->event->id, $attendee->public_id)
            ->assertOk()
            ->assertJsonPath('valid', false)
            ->assertJsonPath('status', 'CANCELLED');
    }

    public function test_awaiting_payment_ticket_is_not_valid(): void
    {
        $attendee = $this->attendee('AWAITING_PAYMENT');

        $this->verify($this->event->id, $attendee->public_id)
            ->assertOk()
            ->assertJsonPath('valid', false)
            ->assertJsonPath('status', 'AWAITING_PAYMENT');
    }

    public function test_unknown_ticket_returns_not_found_and_invalid(): void
    {
        $this->verify($this->event->id, 'A-UNKNOWN123')
            ->assertNotFound()
            ->assertExactJson([
                'valid' => false,
                'status' => null,
                'event_id' => $this->event->id,
                'product_id' => null,
            ]);
    }

    public function test_ticket_from_another_event_is_not_found(): void
    {
        [$otherEvent] = $this->createEventWithProduct();

        $this->verify($otherEvent->id, $this->attendee('ACTIVE')->public_id)
            ->assertNotFound()
            ->assertJsonPath('valid', false);
    }

    public function test_response_never_exposes_personal_data(): void
    {
        $response = $this->verify($this->event->id, $this->attendee('ACTIVE')->public_id)->getContent();

        $this->assertStringNotContainsString('nadia', strtolower($response));
        $this->assertStringNotContainsString('example.test', $response);
    }

    public function test_missing_or_wrong_key_is_rejected(): void
    {
        $attendee = $this->attendee('ACTIVE');
        $url = $this->url($this->event->id, $attendee->public_id);

        $this->getJson($url)->assertUnauthorized();
        $this->getJson($url, ['X-Booth-Key' => 'wrong'])->assertUnauthorized();
    }

    public function test_unconfigured_key_rejects_every_request(): void
    {
        config(['app.booth_api_key' => null]);

        $this->getJson($this->url($this->event->id, $this->attendee('ACTIVE')->public_id), ['X-Booth-Key' => ''])
            ->assertUnauthorized();
    }

    public function test_verification_never_checks_the_ticket_in(): void
    {
        $attendee = $this->attendee('ACTIVE');

        $this->verify($this->event->id, $attendee->public_id)->assertOk();
        $this->verify($this->event->id, $attendee->public_id)->assertOk()->assertJsonPath('valid', true);

        $this->assertSame(0, DB::table('attendee_check_ins')->where('attendee_id', $attendee->id)->count());
    }

    public function test_route_is_rate_limited(): void
    {
        $route = collect(app('router')->getRoutes()->getRoutes())
            ->first(fn ($r) => $r->uri() === 'internal/booth/events/{event_id}/tickets/{attendee_public_id}');

        $this->assertNotNull($route);
        $this->assertContains('throttle:600,1', $route->gatherMiddleware());
    }

    private function verify(int $eventId, string $publicId): TestResponse
    {
        return $this->getJson($this->url($eventId, $publicId), ['X-Booth-Key' => self::KEY]);
    }

    private function url(int $eventId, string $publicId): string
    {
        return "/internal/booth/events/{$eventId}/tickets/{$publicId}";
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
