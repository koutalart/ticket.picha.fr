<?php

declare(strict_types=1);

namespace Tests\Feature\Ticket;

use HiEvents\Models\EventSetting;
use HiEvents\Services\Domain\Ticket\TicketDataFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Support\BoxOfficeTestFixtures;
use Tests\TestCase;

class TicketSponsorNameTest extends TestCase
{
    use BoxOfficeTestFixtures;
    use DatabaseTransactions;

    private const PASSWORD = 'password123!';

    public function test_sponsor_name_saved_in_the_ticket_designer_reaches_the_ticket(): void
    {
        [$event, , , $admin] = $this->createEventWithProduct(userPassword: self::PASSWORD);
        EventSetting::where('event_id', $event->id)->update(['payment_providers' => json_encode(['STRIPE'])]);
        $token = $this->loginAndGetToken($admin, self::PASSWORD);

        $this->patchJson("/events/{$event->id}/settings", [
            'ticket_design_settings' => [
                'date_display_mode' => 'START_DATE_TIME',
                'enabled' => true,
                'sponsor_name' => 'Boissons du Lagon',
            ],
        ], ['Authorization' => 'Bearer '.$token])->assertOk();

        self::assertSame('Boissons du Lagon', app(TicketDataFactory::class)->forPreview($event->id)->sponsorName);
    }

    public function test_sponsor_name_is_limited_to_60_characters(): void
    {
        [$event, , , $admin] = $this->createEventWithProduct(userPassword: self::PASSWORD);
        $token = $this->loginAndGetToken($admin, self::PASSWORD);

        $this->patchJson("/events/{$event->id}/settings", [
            'ticket_design_settings' => ['sponsor_name' => str_repeat('a', 61)],
        ], ['Authorization' => 'Bearer '.$token])->assertUnprocessable();
    }
}
