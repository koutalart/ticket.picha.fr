<?php

declare(strict_types=1);

namespace Tests\Feature\EventAssistant;

use HiEvents\Models\OrganizerSetting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BoxOfficeTestFixtures;
use Tests\TestCase;

class EventAssistantTest extends TestCase
{
    use BoxOfficeTestFixtures;
    use DatabaseTransactions;

    private const PASSWORD = 'password123!';

    protected function setUp(): void
    {
        parent::setUp();
        config(['filesystems.public' => 'public']);
        Storage::fake('public');
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function organizerAndToken(): array
    {
        [$event, , , $admin] = $this->createEventWithProduct(userPassword: self::PASSWORD);
        OrganizerSetting::firstOrCreate(['organizer_id' => $event->organizer_id]);

        return [$event->organizer_id, $this->loginAndGetToken($admin, self::PASSWORD)];
    }

    public function test_assistant_creates_a_draft_event_with_its_ticket_and_venue(): void
    {
        [$organizerId, $token] = $this->organizerAndToken();

        $response = $this->postJson('/events/assistant/create', [
            'organizer_id' => $organizerId,
            'title' => 'Triangle des Bermudes',
            'start_date' => '2026-11-14 18:00',
            'end_date' => '2026-11-15 00:30',
            'venue' => 'Le 5/5, Mamoudzou',
            'description' => "Une soirée <script>alert(1)</script> au 5/5.\n\nDJ et live jusqu'au bout de la nuit.",
            'price' => 45,
            'quantity' => 300,
        ], ['Authorization' => 'Bearer '.$token])->assertCreated();

        $eventId = $response->json('data.id');
        $event = DB::table('events')->find($eventId);
        self::assertSame('DRAFT', $event->status);
        self::assertStringNotContainsString('<script>', $event->description);
        self::assertSame(2, substr_count($event->description, '<p>'));

        $settings = DB::table('event_settings')->where('event_id', $eventId)->first();
        self::assertSame('Le 5/5, Mamoudzou', json_decode($settings->location_details, true)['venue_name']);

        $prices = DB::table('product_prices')->join('products', 'products.id', '=', 'product_prices.product_id')
            ->where('products.event_id', $eventId)->get(['products.type', 'products.product_type', 'product_prices.price', 'product_prices.initial_quantity_available']);
        self::assertCount(1, $prices);
        self::assertSame('PAID', $prices[0]->type);
        self::assertSame('TICKET', $prices[0]->product_type);
        self::assertEquals(45, $prices[0]->price);
        self::assertSame(300, $prices[0]->initial_quantity_available);
    }

    public function test_free_event_gets_a_free_ticket(): void
    {
        [$organizerId, $token] = $this->organizerAndToken();

        $eventId = $this->postJson('/events/assistant/create', [
            'organizer_id' => $organizerId,
            'title' => 'Journée portes ouvertes',
            'start_date' => '2026-10-18 10:00',
            'price' => 0,
        ], ['Authorization' => 'Bearer '.$token])->assertCreated()->json('data.id');

        $product = DB::table('products')->where('event_id', $eventId)->first(['type', 'title']);
        self::assertSame('FREE', $product->type);
    }

    public function test_end_date_must_follow_start_date(): void
    {
        [$organizerId, $token] = $this->organizerAndToken();

        $this->postJson('/events/assistant/create', [
            'organizer_id' => $organizerId,
            'title' => 'Concert',
            'start_date' => '2026-10-18 10:00',
            'end_date' => '2026-10-18 09:00',
            'price' => 10,
        ], ['Authorization' => 'Bearer '.$token])->assertUnprocessable()->assertJsonValidationErrors(['end_date']);
    }

    public function test_ai_is_reported_disabled_and_refused_without_api_key(): void
    {
        config(['services.anthropic.api_key' => null]);
        [, $token] = $this->organizerAndToken();

        $this->getJson('/events/assistant', ['Authorization' => 'Bearer '.$token])
            ->assertOk()->assertJsonPath('data.ai_enabled', false);

        $this->post('/events/assistant/poster', ['image' => UploadedFile::fake()->image('affiche.jpg', 800, 1000)], [
            'Authorization' => 'Bearer '.$token, 'Accept' => 'application/json',
        ])->assertStatus(503);
    }
}
