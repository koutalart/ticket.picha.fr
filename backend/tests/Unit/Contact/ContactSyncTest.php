<?php

declare(strict_types=1);

namespace Tests\Unit\Contact;

use HiEvents\Helper\IdHelper;
use HiEvents\Models\Attendee;
use HiEvents\Models\Event;
use HiEvents\Models\Order;
use HiEvents\Services\Domain\Contact\ContactSyncService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Support\BoxOfficeTestFixtures;
use Tests\TestCase;

class ContactSyncTest extends TestCase
{
    use BoxOfficeTestFixtures;
    use DatabaseTransactions;

    private ContactSyncService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->app->make(ContactSyncService::class);
    }

    public function test_buyer_and_named_attendees_become_contacts_grouped_by_lowercase_email(): void
    {
        [$event, $product, $price] = $this->createEventWithProduct();
        $order = $this->order($event, 'Fatima.Abdallah@Example.test ', 50.00, optedIn: true);
        $this->attendee($order, $product->id, $price->id, 'fatima.abdallah@example.test', 'Fatima');
        $this->attendee($order, $product->id, $price->id, 'said@example.test', 'Saïd');

        $this->service->syncForOrder($order->id);

        $contacts = $this->contacts($event->organizer_id);
        self::assertSame(['fatima.abdallah@example.test', 'said@example.test'], $contacts->pluck('email')->all());

        $buyer = $contacts->firstWhere('email', 'fatima.abdallah@example.test');
        self::assertSame(1, $buyer->orders_count);
        self::assertSame(1, $buyer->events_count);
        self::assertEquals(50.00, (float) $buyer->total_spent);
        self::assertSame('ORDER', $buyer->source);
        self::assertSame('CHECKOUT', $buyer->email_consent_source);
        self::assertNotNull($buyer->email_consent_at);

        $guest = $contacts->firstWhere('email', 'said@example.test');
        self::assertSame(0, $guest->orders_count);
        self::assertSame(1, $guest->events_count);
        self::assertSame('ATTENDEE', $guest->source);
        self::assertNull($guest->email_consent_at);
    }

    public function test_statistics_add_up_across_events_and_count_scanned_attendance(): void
    {
        [$event, $product, $price] = $this->createEventWithProduct();
        $secondEvent = $this->secondEventOfSameOrganizer($event);

        $first = $this->order($event, 'client@example.test', 25.00);
        $attendee = $this->attendee($first, $product->id, $price->id, 'client@example.test', 'Client');
        $this->order($secondEvent, 'CLIENT@example.test', 40.00, refunded: 10.00);

        $checkInList = $this->attachCheckInList($event, $product);

        DB::table('attendee_check_ins')->insert([
            'short_id' => IdHelper::shortId('ci'),
            'check_in_list_id' => $checkInList->id,
            'ip_address' => '127.0.0.1',
            'attendee_id' => $attendee->id,
            'event_id' => $event->id,
            'order_id' => $first->id,
            'product_id' => $product->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->service->syncOrganizer($event->organizer_id);

        $contact = $this->contacts($event->organizer_id)->sole();
        self::assertSame(2, $contact->orders_count);
        self::assertSame(2, $contact->events_count);
        self::assertSame(1, $contact->attended_events_count);
        self::assertEquals(55.00, (float) $contact->total_spent);
    }

    public function test_unpaid_orders_and_kiosk_placeholder_emails_are_ignored(): void
    {
        [$event] = $this->createEventWithProduct();
        $this->order($event, 'abandon@example.test', 25.00, status: 'ABANDONED');
        $this->order($event, 'kiosk.s1@no-mail.picha.invalid', 25.00);

        $this->service->syncOrganizer($event->organizer_id);

        self::assertCount(0, $this->contacts($event->organizer_id));
    }

    public function test_cancelled_order_resets_statistics_but_keeps_the_contact(): void
    {
        [$event] = $this->createEventWithProduct();
        $order = $this->order($event, 'client@example.test', 25.00);
        $this->service->syncForOrder($order->id);

        Order::whereKey($order->id)->update(['status' => 'CANCELLED']);
        $this->service->syncForOrder($order->id);

        $contact = $this->contacts($event->organizer_id)->sole();
        self::assertSame(0, $contact->orders_count);
        self::assertEquals(0, (float) $contact->total_spent);
    }

    public function test_contacts_are_never_shared_between_organizers(): void
    {
        [$eventA] = $this->createEventWithProduct();
        [$eventB] = $this->createEventWithProduct();
        $this->order($eventA, 'client@example.test', 25.00);

        $this->service->syncOrganizer($eventA->organizer_id);
        $this->service->syncOrganizer($eventB->organizer_id);

        self::assertCount(1, $this->contacts($eventA->organizer_id));
        self::assertCount(0, $this->contacts($eventB->organizer_id));
    }

    public function test_sync_keeps_names_already_on_the_contact_and_a_later_consent(): void
    {
        [$event] = $this->createEventWithProduct();
        $order = $this->order($event, 'client@example.test', 25.00);
        $this->service->syncForOrder($order->id);

        DB::table('contacts')->where('email', 'client@example.test')->update([
            'first_name' => 'Prénom corrigé',
            'email_consent_at' => now()->addDay(),
            'email_consent_source' => 'IMPORT',
        ]);

        $this->service->syncForOrder($order->id);

        $contact = $this->contacts($event->organizer_id)->sole();
        self::assertSame('Prénom corrigé', $contact->first_name);
        self::assertSame('IMPORT', $contact->email_consent_source);
    }

    public function test_buyer_phone_is_saved_on_the_contact_without_overwriting_an_existing_one(): void
    {
        [$event] = $this->createEventWithProduct();
        $first = $this->order($event, 'client@example.test', 25.00, phone: '+262639111111');
        $this->service->syncForOrder($first->id);

        self::assertSame('+262639111111', $this->contacts($event->organizer_id)->sole()->phone);

        $second = $this->order($event, 'client@example.test', 25.00, phone: '+262639222222');
        $this->service->syncForOrder($second->id);

        self::assertSame('+262639111111', $this->contacts($event->organizer_id)->sole()->phone);
    }

    public function test_sms_contact_with_the_same_phone_is_merged_into_the_buyer_contact(): void
    {
        [$event] = $this->createEventWithProduct();
        [$otherEvent] = $this->createEventWithProduct();
        $consentAt = now()->subDays(3)->startOfSecond();

        $smsContactId = $this->smsContact($event->organizer_id, '+33639123456', 'Fatima', $consentAt);
        $otherOrganizerContactId = $this->smsContact($otherEvent->organizer_id, '+262639123456', 'Autre', $consentAt);

        $order = $this->order($event, 'fatima@example.test', 25.00, phone: '+262639123456');
        $this->service->syncForOrder($order->id);

        $buyer = DB::table('contacts')->where('email', 'fatima@example.test')->sole();
        self::assertSame('+262639123456', $buyer->phone);
        self::assertSame('SMS_KEYWORD', $buyer->sms_consent_source);
        self::assertEquals($consentAt, \Carbon\Carbon::parse($buyer->sms_consent_at));
        self::assertNull($buyer->merged_into_contact_id);

        self::assertSame($buyer->id, DB::table('contacts')->where('id', $smsContactId)->value('merged_into_contact_id'));
        self::assertNull(DB::table('contacts')->where('id', $otherOrganizerContactId)->value('merged_into_contact_id'));
    }

    public function test_normalize_email(): void
    {
        self::assertSame('a@b.fr', ContactSyncService::normalizeEmail('  A@B.fr '));
        self::assertNull(ContactSyncService::normalizeEmail(''));
        self::assertNull(ContactSyncService::normalizeEmail(null));
        self::assertNull(ContactSyncService::normalizeEmail('x@no-mail.picha.invalid'));
    }

    private function contacts(int $organizerId)
    {
        return DB::table('contacts')->where('organizer_id', $organizerId)->orderBy('email')->get();
    }

    private function smsContact(int $organizerId, string $phone, string $firstName, \DateTimeInterface $consentAt): int
    {
        return DB::table('contacts')->insertGetId([
            'organizer_id' => $organizerId,
            'phone' => $phone,
            'first_name' => $firstName,
            'source' => 'SMS_CAMPAIGN',
            'sms_consent_at' => $consentAt,
            'sms_consent_source' => 'SMS_KEYWORD',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function order(Event $event, string $email, float $total, bool $optedIn = false, string $status = 'COMPLETED', float $refunded = 0, ?string $phone = null): Order
    {
        return Order::create([
            'phone' => $phone,
            'event_id' => $event->id,
            'total_gross' => $total,
            'total_refunded' => $refunded,
            'currency' => 'EUR',
            'status' => $status,
            'payment_status' => 'PAYMENT_RECEIVED',
            'email' => $email,
            'first_name' => 'Client',
            'last_name' => 'Test',
            'opted_into_marketing_at' => $optedIn ? now() : null,
            'short_id' => IdHelper::shortId(IdHelper::ORDER_PREFIX),
            'public_id' => IdHelper::publicId(IdHelper::ORDER_PREFIX),
        ]);
    }

    private function attendee(Order $order, int $productId, int $priceId, string $email, string $firstName): Attendee
    {
        return Attendee::create([
            'event_id' => $order->event_id,
            'product_id' => $productId,
            'product_price_id' => $priceId,
            'order_id' => $order->id,
            'status' => 'ACTIVE',
            'email' => $email,
            'first_name' => $firstName,
            'last_name' => 'Test',
            'short_id' => IdHelper::shortId(IdHelper::ATTENDEE_PREFIX),
            'public_id' => IdHelper::publicId(IdHelper::ATTENDEE_PREFIX),
        ]);
    }

    private function secondEventOfSameOrganizer(Event $event): Event
    {
        return Event::create([
            'title' => 'Second Event',
            'account_id' => $event->account_id,
            'organizer_id' => $event->organizer_id,
            'currency' => 'EUR',
            'status' => 'LIVE',
            'short_id' => IdHelper::shortId(IdHelper::EVENT_PREFIX),
        ]);
    }
}
