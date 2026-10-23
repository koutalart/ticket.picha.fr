<?php

declare(strict_types=1);

namespace Tests\Unit\Contact;

use HiEvents\Helper\IdHelper;
use HiEvents\Models\Event;
use HiEvents\Models\Order;
use HiEvents\Services\Domain\Contact\ContactSyncService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\Support\BoxOfficeTestFixtures;
use Tests\TestCase;

class ContactsApiTest extends TestCase
{
    use BoxOfficeTestFixtures;
    use DatabaseTransactions;

    private const PASSWORD = 'Password-crm-1';

    private Event $event;

    private Event $foreignEvent;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->foreignEvent] = $this->createEventWithProduct();
        [$this->event, , , $user] = $this->createEventWithProduct(userPassword: self::PASSWORD);
        $this->token = $this->loginAndGetToken($user, self::PASSWORD);

        $this->order('fatima@example.test', 50.00, optedIn: true);
        $this->order('kevin@example.test', 20.00);
        $this->app->make(ContactSyncService::class)->syncOrganizer($this->event->organizer_id);
    }

    public function test_list_search_and_consent_filter(): void
    {
        $this->api('get', 'contacts')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->api('get', 'contacts?query=fati')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.email', 'fatima@example.test')
            ->assertJsonPath('data.0.email_consent', true);

        $this->api('get', 'contacts?email_consent=1')
            ->assertJsonCount(1, 'data');

        $this->api('get', 'contacts/summary')
            ->assertOk()
            ->assertJsonPath('data.total', 2)
            ->assertJsonPath('data.email_consent', 1);
    }

    public function test_event_filter_refuses_to_reveal_purchases_at_another_organizer(): void
    {
        $foreignEvent = $this->foreignEvent;

        $this->api('get', "contacts?event_id={$this->event->id}")->assertJsonCount(2, 'data');
        $this->api('get', "contacts?event_id={$foreignEvent->id}")->assertJsonCount(0, 'data');
    }

    public function test_another_account_cannot_read_the_contacts(): void
    {
        $intruder = $this->createUnrelatedOrganizerUser(self::PASSWORD);
        $intruderToken = $this->loginAndGetToken($intruder, self::PASSWORD);

        $this->getJson("/organizers/{$this->event->organizer_id}/contacts", ['Authorization' => 'Bearer '.$intruderToken])
            ->assertForbidden();
    }

    public function test_contact_of_another_organizer_is_not_found(): void
    {
        $foreignEvent = $this->foreignEvent;
        $this->order('stranger@example.test', 10.00, $foreignEvent);
        $this->app->make(ContactSyncService::class)->syncOrganizer($foreignEvent->organizer_id);
        $foreignContactId = DB::table('contacts')->where('email', 'stranger@example.test')->value('id');

        $this->api('get', "contacts/{$foreignContactId}")->assertNotFound();
        $this->api('get', "contacts/{$foreignContactId}/timeline")->assertNotFound();
    }

    public function test_fiche_timeline_notes_and_tags(): void
    {
        $contactId = $this->contactId('fatima@example.test');

        $this->api('get', "contacts/{$contactId}")
            ->assertOk()
            ->assertJsonPath('data.orders_count', 1);

        $this->api('get', "contacts/{$contactId}/timeline")
            ->assertOk()
            ->assertJsonPath('data.0.type', 'ORDER')
            ->assertJsonPath('data.0.event_title', 'Test Event');

        $this->api('post', "contacts/{$contactId}/notes", ['note' => '<b>Vient</b> en groupe'])
            ->assertCreated()
            ->assertJsonPath('data.note', 'Vient en groupe');
        $noteId = DB::table('contact_notes')->where('contact_id', $contactId)->value('id');
        $this->api('get', "contacts/{$contactId}/notes")->assertJsonCount(1, 'data');
        $this->api('delete', "contacts/{$contactId}/notes/{$noteId}")->assertNoContent();

        $this->api('post', 'contacts/tags', ['name' => 'VIP', 'contact_ids' => [$contactId]])->assertOk();
        $this->api('post', 'contacts/tags', ['name' => 'vip', 'contact_ids' => [$contactId]])->assertOk();
        $this->api('get', 'contacts/tags')->assertJsonCount(1, 'data')->assertJsonPath('data.0.contacts_count', 1);
        $tagId = DB::table('contact_tags')->where('organizer_id', $this->event->organizer_id)->value('id');
        $this->api('get', "contacts?tag_id={$tagId}")->assertJsonCount(1, 'data')->assertJsonPath('data.0.tags.0.name', 'VIP');
        $this->api('delete', "contacts/{$contactId}/tags/{$tagId}")->assertNoContent();
        $this->api('get', "contacts?tag_id={$tagId}")->assertJsonCount(0, 'data');
    }

    public function test_update_phone_and_consents(): void
    {
        $contactId = $this->contactId('kevin@example.test');

        $this->api('put', "contacts/{$contactId}", [
            'first_name' => 'Kevin', 'last_name' => 'Madi', 'phone' => '0639 12 34 56',
            'email_consent' => true, 'sms_consent' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.phone', '+262639123456')
            ->assertJsonPath('data.email_consent', true)
            ->assertJsonPath('data.sms_consent', true)
            ->assertJsonPath('data.sms_consent_source', 'MANUAL');

        $this->api('put', "contacts/{$contactId}", [
            'phone' => '0639 12 34 56', 'email_consent' => false, 'sms_consent' => false,
        ])
            ->assertJsonPath('data.email_consent', false)
            ->assertJsonPath('data.sms_consent', false);

        $this->api('put', "contacts/{$contactId}", ['phone' => 'abc', 'email_consent' => false, 'sms_consent' => false])
            ->assertUnprocessable();
        $this->api('put', "contacts/{$contactId}", ['phone' => null, 'email_consent' => false, 'sms_consent' => true])
            ->assertUnprocessable();
    }

    public function test_export_is_a_semicolon_csv(): void
    {
        $response = $this->api('get', 'contacts/export?email_consent=1')->assertOk();
        $csv = $response->streamedContent();

        self::assertStringContainsString('fatima@example.test', $csv);
        self::assertStringNotContainsString('kevin@example.test', $csv);
        self::assertStringContainsString(';', $csv);
    }

    public function test_import_a_french_excel_csv_in_two_steps(): void
    {
        $csv = mb_convert_encoding("Prénom;Nom;E-mail;Portable;Ville\nZaïna;AHMED;zaina@example.test;0639 44 55 66;Mamoudzou\nKevin;Madi;KEVIN@example.test;;Dembéni\n", 'Windows-1252', 'UTF-8');
        $file = UploadedFile::fake()->createWithContent('clients.csv', $csv);

        $upload = $this->withHeaders(['Authorization' => 'Bearer '.$this->token])
            ->post("/organizers/{$this->event->organizer_id}/contacts/imports/upload", ['file' => $file])
            ->assertOk()
            ->assertJsonPath('data.row_count', 2)
            ->assertJsonPath('data.headers.0', 'Prénom')
            ->assertJsonPath('data.suggested_mapping', ['FIRST_NAME', 'LAST_NAME', 'EMAIL', 'PHONE', 'IGNORE'])
            ->json('data');

        $payload = [
            'import_token' => $upload['import_token'],
            'file_name' => 'clients.csv',
            'mapping' => ['FIRST_NAME', 'LAST_NAME', 'EMAIL', 'PHONE', 'TAG'],
            'tag_name' => 'Import test',
            'email_consent' => true,
            'sms_consent' => true,
            'dry_run' => true,
        ];

        $this->api('post', 'contacts/imports', $payload)
            ->assertOk()
            ->assertJsonPath('data.created_count', 1)
            ->assertJsonPath('data.updated_count', 1);

        $this->api('post', 'contacts/imports', [...$payload, 'dry_run' => false])
            ->assertOk()
            ->assertJsonPath('data.created_count', 1);

        $this->api('get', 'contacts?query=zaina')
            ->assertJsonPath('data.0.phone', '+262639445566')
            ->assertJsonPath('data.0.sms_consent', true);

        $this->api('post', 'contacts/imports', [...$payload, 'dry_run' => false])->assertUnprocessable();
    }

    public function test_import_requires_an_email_or_phone_column(): void
    {
        $file = UploadedFile::fake()->createWithContent('clients.csv', "Nom\nMadi\n");
        $token = $this->withHeaders(['Authorization' => 'Bearer '.$this->token])
            ->post("/organizers/{$this->event->organizer_id}/contacts/imports/upload", ['file' => $file])
            ->json('data.import_token');

        $this->api('post', 'contacts/imports', [
            'import_token' => $token, 'file_name' => 'clients.csv', 'mapping' => ['LAST_NAME'],
            'email_consent' => false, 'sms_consent' => false, 'dry_run' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('mapping');
    }

    public function test_create_a_contact_by_hand(): void
    {
        $this->api('post', 'contacts', [
            'first_name' => 'Anissa', 'last_name' => 'Soilihi', 'email' => 'Anissa@Example.test',
            'phone' => '0639 98 76 54', 'email_consent' => true, 'sms_consent' => false,
        ])
            ->assertCreated()
            ->assertJsonPath('data.email', 'anissa@example.test')
            ->assertJsonPath('data.source', 'MANUAL')
            ->assertJsonPath('data.email_consent', true);

        $this->api('post', 'contacts', ['email' => 'fatima@example.test', 'email_consent' => false, 'sms_consent' => false])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    private function api(string $method, string $path, array $data = [])
    {
        return $this->json(
            strtoupper($method),
            "/organizers/{$this->event->organizer_id}/{$path}",
            $data,
            ['Authorization' => 'Bearer '.$this->token],
        );
    }

    private function contactId(string $email): int
    {
        return (int) DB::table('contacts')->where('organizer_id', $this->event->organizer_id)->where('email', $email)->value('id');
    }

    private function order(string $email, float $total, ?Event $event = null, bool $optedIn = false): Order
    {
        return Order::create([
            'event_id' => ($event ?? $this->event)->id,
            'total_gross' => $total,
            'total_refunded' => 0,
            'currency' => 'EUR',
            'status' => 'COMPLETED',
            'payment_status' => 'PAYMENT_RECEIVED',
            'email' => $email,
            'first_name' => ucfirst(strstr($email, '@', true)),
            'last_name' => 'Test',
            'opted_into_marketing_at' => $optedIn ? now() : null,
            'short_id' => IdHelper::shortId(IdHelper::ORDER_PREFIX),
            'public_id' => IdHelper::publicId(IdHelper::ORDER_PREFIX),
        ]);
    }
}
