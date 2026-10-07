<?php

declare(strict_types=1);

namespace Tests\Feature\BoxOffice;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\Support\BoxOfficeTestFixtures;
use Tests\TestCase;

class BoxOfficeOperatorCreateScopeTest extends TestCase
{
    use BoxOfficeTestFixtures;
    use DatabaseTransactions;

    private const PASSWORD = 'password123!';

    public function test_operator_cannot_create_an_event(): void
    {
        [$event] = $this->createEventWithProduct(price: 25.00);
        $token = $this->loginAndGetToken($this->makeBoxOfficeOperator($event, self::PASSWORD), self::PASSWORD);
        $before = DB::table('events')->count();

        $this->postJson('/events', [
            'title' => 'Événement pirate',
            'organizer_id' => $event->organizer_id,
            'start_date' => '2030-01-01 20:00',
            'currency' => 'EUR',
            'timezone' => 'Indian/Mayotte',
        ], ['Authorization' => 'Bearer '.$token])->assertForbidden();

        self::assertSame($before, DB::table('events')->count());
    }

    public function test_operator_cannot_create_an_organizer(): void
    {
        [$event] = $this->createEventWithProduct(price: 25.00);
        $token = $this->loginAndGetToken($this->makeBoxOfficeOperator($event, self::PASSWORD), self::PASSWORD);
        $before = DB::table('organizers')->count();

        $this->postJson('/organizers', [
            'name' => 'Organisateur pirate',
            'email' => 'pirate@example.test',
            'currency' => 'EUR',
            'timezone' => 'Indian/Mayotte',
        ], ['Authorization' => 'Bearer '.$token])->assertForbidden();

        self::assertSame($before, DB::table('organizers')->count());
    }

    public function test_operator_cannot_list_the_account_organizers(): void
    {
        [$event] = $this->createEventWithProduct(price: 25.00);
        $token = $this->loginAndGetToken($this->makeBoxOfficeOperator($event, self::PASSWORD), self::PASSWORD);

        $this->getJson('/organizers', ['Authorization' => 'Bearer '.$token])->assertForbidden();
    }

    public function test_organizer_can_still_list_organizers(): void
    {
        [$event, , , $admin] = $this->createEventWithProduct(price: 25.00, userPassword: self::PASSWORD);
        $token = $this->loginAndGetToken($admin, self::PASSWORD);

        $this->getJson('/organizers', ['Authorization' => 'Bearer '.$token])->assertOk();
    }

    public function test_operator_cannot_replace_the_event_ticket_logo(): void
    {
        [$event] = $this->createEventWithProduct(price: 25.00);
        $token = $this->loginAndGetToken($this->makeBoxOfficeOperator($event, self::PASSWORD), self::PASSWORD);

        $this->post('/images', [
            'image' => UploadedFile::fake()->image('logo.png', 400, 400),
            'image_type' => 'TICKET_LOGO',
            'entity_id' => $event->id,
        ], ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'])->assertForbidden();

        self::assertSame(0, DB::table('images')->where('entity_id', $event->id)->where('type', 'TICKET_LOGO')->count());
    }
}
