<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PublicEmailEndpointsThrottleTest extends TestCase
{
    use DatabaseTransactions;

    public function test_ticket_lookup_is_rate_limited_per_minute(): void
    {
        Mail::fake();

        for ($i = 0; $i < 5; $i++) {
            self::assertNotSame(429, $this->postJson('/public/ticket-lookup', ['email' => 'client@example.test'])->status());
        }

        $this->postJson('/public/ticket-lookup', ['email' => 'client@example.test'])->assertStatus(429);
    }

    public function test_organizer_contact_form_is_rate_limited_per_minute(): void
    {
        for ($i = 0; $i < 5; $i++) {
            self::assertNotSame(429, $this->postJson('/public/organizers/999999/contact', [])->status());
        }

        $this->postJson('/public/organizers/999999/contact', [])->assertStatus(429);
    }
}
