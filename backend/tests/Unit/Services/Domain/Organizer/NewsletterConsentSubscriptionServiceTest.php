<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Domain\Organizer;

use Carbon\Carbon;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\Events\OrderStatusChangedEvent;
use HiEvents\Helper\IdHelper;
use HiEvents\Jobs\Organizer\SubscribeConsentingBuyerToNewsletterJob;
use HiEvents\Listeners\Order\SubscribeConsentingBuyerToNewsletterListener;
use HiEvents\Models\Attendee;
use HiEvents\Models\Event;
use HiEvents\Models\Order;
use HiEvents\Repository\Interfaces\OrganizerNewsletterSubscriberRepositoryInterface;
use HiEvents\Services\Application\Handlers\Organizer\DTO\NewsletterSubscriberSearchDTO;
use HiEvents\Services\Domain\Organizer\NewsletterConsentSubscriptionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Psr\Log\NullLogger;
use Tests\Support\BoxOfficeTestFixtures;
use Tests\TestCase;

class NewsletterConsentSubscriptionServiceTest extends TestCase
{
    use BoxOfficeTestFixtures;
    use DatabaseTransactions;

    private NewsletterConsentSubscriptionService $service;

    private OrganizerNewsletterSubscriberRepositoryInterface $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->app->make(NewsletterConsentSubscriptionService::class);
        $this->repository = $this->app->make(OrganizerNewsletterSubscriberRepositoryInterface::class);
    }

    public function test_a_consenting_buyer_is_added_with_the_registration_source(): void
    {
        [$event, $product, $price] = $this->createEventWithProduct();
        $consentAt = Carbon::parse('2026-10-20 18:30:00');
        $order = $this->order($event, ' Fatima@Example.test', $consentAt);

        self::assertSame(1, $this->service->subscribeFromOrder($order->id));

        $subscriber = $this->subscribers($event->organizer_id)->sole();
        self::assertSame('fatima@example.test', $subscriber->email);
        self::assertSame('REGISTRATION', $subscriber->source);
        self::assertSame($order->id, $subscriber->order_id);
        self::assertSame($event->id, $subscriber->event_id);
        self::assertSame('2026-10-20 18:30:00', Carbon::parse($subscriber->consented_at)->format('Y-m-d H:i:s'));
        self::assertStringContainsString('Test Organizer', $subscriber->consent_text);

        $listed = $this->repository->searchForOrganizer($event->organizer_id, new NewsletterSubscriberSearchDTO(query: null, page: 1, perPage: 25));
        self::assertSame('Registration: Test Event', $listed->items()[0]->getSourceLabel());
    }

    public function test_buyers_without_consent_and_other_attendees_are_never_added(): void
    {
        [$event, $product, $price] = $this->createEventWithProduct();
        $withoutConsent = $this->order($event, 'non@example.test', null);
        $withConsent = $this->order($event, 'oui@example.test', now());
        $this->attendee($withConsent, $product->id, $price->id, 'invite@example.test');
        $this->order($event, 'abandon@example.test', now(), status: 'ABANDONED');
        $this->order($event, 'kiosk.s1@no-mail.picha.invalid', now());

        self::assertSame(0, $this->service->subscribeFromOrder($withoutConsent->id));
        $this->service->subscribeOrganizer($event->organizer_id);

        self::assertSame(['oui@example.test'], $this->subscribers($event->organizer_id)->pluck('email')->all());
    }

    public function test_subscription_is_idempotent_and_keeps_an_existing_footer_subscription(): void
    {
        [$event] = $this->createEventWithProduct();
        $this->order($event, 'client@example.test', now()->subDay());
        $this->order($event, 'CLIENT@example.test', now());
        $this->repository->createIfNotSubscribed([
            'organizer_id' => $event->organizer_id,
            'event_id' => $event->id,
            'email' => 'footer@example.test',
            'consent_text' => 'Pied de page',
            'consented_at' => now()->subWeek(),
        ]);
        $this->order($event, 'footer@example.test', now());

        self::assertSame(1, $this->service->subscribeOrganizer($event->organizer_id));
        self::assertSame(0, $this->service->subscribeOrganizer($event->organizer_id));

        $subscribers = $this->subscribers($event->organizer_id);
        self::assertSame(['client@example.test', 'footer@example.test'], $subscribers->pluck('email')->all());
        self::assertSame('FOOTER', $subscribers->firstWhere('email', 'footer@example.test')->source);
    }

    public function test_an_unsubscribe_is_never_overridden_by_an_older_consent(): void
    {
        [$event] = $this->createEventWithProduct();
        $this->order($event, 'client@example.test', now()->subDays(10));
        $this->service->subscribeOrganizer($event->organizer_id);
        $subscriber = $this->subscribers($event->organizer_id)->sole();

        self::assertTrue($this->repository->unsubscribe($event->organizer_id, $subscriber->id));
        self::assertFalse($this->repository->unsubscribe($event->organizer_id, $subscriber->id));

        self::assertSame(0, $this->service->subscribeOrganizer($event->organizer_id, dryRun: true));
        self::assertSame(0, $this->service->subscribeOrganizer($event->organizer_id));
        self::assertSame(0, $this->listed($event->organizer_id));
        self::assertNotNull($this->subscribers($event->organizer_id)->sole()->unsubscribed_at);
    }

    public function test_a_new_explicit_consent_after_the_unsubscribe_re_subscribes_and_keeps_the_unsubscribe_date(): void
    {
        [$event] = $this->createEventWithProduct();
        $this->order($event, 'client@example.test', now()->subDays(10));
        $this->service->subscribeOrganizer($event->organizer_id);
        $subscriber = $this->subscribers($event->organizer_id)->sole();
        DB::table('organizer_newsletter_subscribers')->where('id', $subscriber->id)->update(['unsubscribed_at' => now()->subDays(5)]);

        $newOrder = $this->order($event, 'client@example.test', now());
        self::assertSame(1, $this->service->subscribeFromOrder($newOrder->id));

        $subscriber = $this->subscribers($event->organizer_id)->sole();
        self::assertSame($newOrder->id, $subscriber->order_id);
        self::assertNotNull($subscriber->unsubscribed_at);
        self::assertSame(1, $this->listed($event->organizer_id));
    }

    public function test_an_unsubscribe_recorded_on_the_crm_contact_is_honoured(): void
    {
        [$event] = $this->createEventWithProduct();
        $this->order($event, 'client@example.test', now()->subDays(10));
        DB::table('contacts')->insert([
            'organizer_id' => $event->organizer_id,
            'email' => 'client@example.test',
            'source' => 'ORDER',
            'email_unsubscribed_at' => now()->subDays(5),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        self::assertSame(0, $this->service->subscribeOrganizer($event->organizer_id));
        self::assertCount(0, $this->subscribers($event->organizer_id));

        $this->repository->createIfNotSubscribed([
            'organizer_id' => $event->organizer_id,
            'event_id' => $event->id,
            'email' => 'client@example.test',
            'consent_text' => 'Pied de page',
            'consented_at' => now()->subDays(7),
        ]);
        self::assertSame(0, $this->listed($event->organizer_id));
    }

    public function test_a_footer_subscription_after_an_unsubscribe_re_subscribes(): void
    {
        [$event] = $this->createEventWithProduct();
        $attributes = [
            'organizer_id' => $event->organizer_id,
            'event_id' => $event->id,
            'email' => 'client@example.test',
            'consent_text' => 'Pied de page',
            'consented_at' => now()->subDays(10),
        ];
        self::assertTrue($this->repository->createIfNotSubscribed($attributes));
        self::assertFalse($this->repository->createIfNotSubscribed($attributes));
        $id = $this->subscribers($event->organizer_id)->sole()->id;
        DB::table('organizer_newsletter_subscribers')->where('id', $id)->update(['unsubscribed_at' => now()->subDays(5)]);

        self::assertTrue($this->repository->createIfNotSubscribed([...$attributes, 'consented_at' => now()]));
        self::assertSame(1, $this->listed($event->organizer_id));
    }

    public function test_backfill_dry_run_counts_without_writing(): void
    {
        [$event] = $this->createEventWithProduct();
        $this->order($event, 'a@example.test', now());
        $this->order($event, 'b@example.test', now());
        $this->order($event, 'B@example.test', now());
        $this->order($event, 'c@example.test', null);

        Artisan::call('newsletter:backfill-consents', ['--organizer' => $event->organizer_id, '--dry-run' => true]);
        self::assertStringContainsString("Organizer {$event->organizer_id}: 2 subscriber(s) would be added", Artisan::output());
        self::assertCount(0, $this->subscribers($event->organizer_id));

        Artisan::call('newsletter:backfill-consents', ['--organizer' => $event->organizer_id]);
        self::assertStringContainsString("Organizer {$event->organizer_id}: 2 subscriber(s) added", Artisan::output());
        self::assertCount(2, $this->subscribers($event->organizer_id));
    }

    public function test_listener_queues_only_consenting_completed_orders(): void
    {
        Bus::fake();
        $listener = new SubscribeConsentingBuyerToNewsletterListener(new NullLogger);

        $listener->handle(new OrderStatusChangedEvent($this->orderObject(5, 'COMPLETED', '2026-10-22 10:00:00')));
        $listener->handle(new OrderStatusChangedEvent($this->orderObject(6, 'COMPLETED', null)));
        $listener->handle(new OrderStatusChangedEvent($this->orderObject(7, 'RESERVED', '2026-10-22 10:00:00')));

        Bus::assertDispatchedTimes(SubscribeConsentingBuyerToNewsletterJob::class, 1);
        Bus::assertDispatched(SubscribeConsentingBuyerToNewsletterJob::class, fn ($job) => $job->orderId === 5);
    }

    private function orderObject(int $id, string $status, ?string $optedInAt): OrderDomainObject
    {
        return (new OrderDomainObject)->setId($id)->setStatus($status)->setOptedIntoMarketingAt($optedInAt);
    }

    private function listed(int $organizerId): int
    {
        return $this->repository
            ->searchForOrganizer($organizerId, new NewsletterSubscriberSearchDTO(query: null, page: 1, perPage: 25))
            ->total();
    }

    private function subscribers(int $organizerId)
    {
        return DB::table('organizer_newsletter_subscribers')->where('organizer_id', $organizerId)->orderBy('email')->get();
    }

    private function order(Event $event, string $email, ?Carbon $optedInAt, string $status = 'COMPLETED'): Order
    {
        return Order::create([
            'event_id' => $event->id,
            'total_gross' => 25,
            'total_refunded' => 0,
            'currency' => 'EUR',
            'status' => $status,
            'payment_status' => 'PAYMENT_RECEIVED',
            'email' => $email,
            'first_name' => 'Client',
            'last_name' => 'Test',
            'opted_into_marketing_at' => $optedInAt,
            'short_id' => IdHelper::shortId(IdHelper::ORDER_PREFIX),
            'public_id' => IdHelper::publicId(IdHelper::ORDER_PREFIX),
        ]);
    }

    private function attendee(Order $order, int $productId, int $priceId, string $email): Attendee
    {
        return Attendee::create([
            'order_id' => $order->id,
            'event_id' => $order->event_id,
            'product_id' => $productId,
            'product_price_id' => $priceId,
            'email' => $email,
            'first_name' => 'Invité',
            'last_name' => 'Test',
            'status' => 'ACTIVE',
            'short_id' => IdHelper::shortId(IdHelper::ATTENDEE_PREFIX),
            'public_id' => IdHelper::publicId(IdHelper::ATTENDEE_PREFIX),
        ]);
    }
}
