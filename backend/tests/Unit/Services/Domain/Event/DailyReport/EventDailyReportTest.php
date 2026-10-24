<?php

namespace Tests\Unit\Services\Domain\Event\DailyReport;

use HiEvents\DomainObjects\Enums\OrganizerTeamContactRole;
use HiEvents\DomainObjects\EventDailyReportDomainObject;
use HiEvents\Models\Event;
use HiEvents\Models\OrganizerSmsSetting;
use HiEvents\Models\Product;
use HiEvents\Models\ProductPrice;
use HiEvents\Models\SmsConversation;
use HiEvents\Services\Domain\Event\DailyReport\EventDailyReportService;
use HiEvents\Services\Domain\Sms\Team\OrganizerTeamContactService;
use HiEvents\Services\Infrastructure\HttpSms\HttpSmsClient;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\Support\BoxOfficeTestFixtures;
use Tests\TestCase;

class EventDailyReportTest extends TestCase
{
    use BoxOfficeTestFixtures;
    use DatabaseTransactions;

    private const LINE_NUMBER = '+262639000000';

    private const RESPONSABLE = '+262639689691';

    private const COPY = '+262639250206';

    private const SUPERVISOR = '+262639780773';

    private Event $event;

    private Product $ticket;

    private ProductPrice $ticketPrice;

    private Product $option;

    private ProductPrice $optionPrice;

    private Product $protection;

    private ProductPrice $protectionPrice;

    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-24 16:45:00', 'UTC'));
        DB::table('organizer_sms_settings')->update(['api_key' => null, 'shared_line_settings_id' => null]);
        config(['services.platform_supervisor.phones' => self::SUPERVISOR]);

        [$this->event, $this->ticket, $this->ticketPrice] = $this->createEventWithProduct(price: 15.00);
        DB::table('events')->where('id', $this->event->id)->update(['title' => 'Marche des Amis', 'currency' => 'EUR']);

        [$this->option, $this->optionPrice] = $this->product('T-shirt', 'GENERAL', 10.00);
        [$this->protection, $this->protectionPrice] = $this->product('Option sérénité', 'TICKET', 0.49, isRefundProtection: true);

        OrganizerSmsSetting::create([
            'account_id' => $this->event->account_id,
            'organizer_id' => $this->event->organizer_id,
            'webhook_token' => Str::random(40),
            'webhook_signing_key' => 'key',
            'api_key' => 'uk_line',
            'from_number' => self::LINE_NUMBER,
        ]);

        $this->mock(HttpSmsClient::class, function (MockInterface $client) {
            $client->shouldReceive('sendMessage')->andReturnUsing(function (string $apiKey, string $from, string $to, string $content) {
                $this->sent[] = compact('from', 'to', 'content');

                return 'provider-'.count($this->sent);
            });
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_report_counts_paid_tickets_only_and_their_price_without_fees(): void
    {
        $this->order('COMPLETED', tickets: 2, protections: 2, options: 1, createdAt: '2026-10-23 10:00:00');
        $this->order('COMPLETED', tickets: 1, protections: 0, options: 0, createdAt: '2026-10-24 06:00:00');
        $this->order('COMPLETED', tickets: 1, protections: 1, options: 0, createdAt: '2026-10-24 07:00:00', refundStatus: 'REFUNDED');
        $this->order('COMPLETED', tickets: 1, protections: 0, options: 0, createdAt: '2026-10-24 08:00:00', attendeeStatus: 'CANCELLED');
        $this->order('AWAITING_OFFLINE_PAYMENT', tickets: 1, protections: 0, options: 0, createdAt: '2026-10-24 09:00:00');
        $this->order('RESERVED', tickets: 1, protections: 0, options: 0, createdAt: '2026-10-24 16:40:00', reservedUntil: '2026-10-24 16:55:00');
        $this->order('RESERVED', tickets: 1, protections: 0, options: 0, createdAt: '2026-10-24 15:00:00', reservedUntil: '2026-10-24 15:15:00');

        $report = app(EventDailyReportService::class)->build($this->event->id);

        $this->assertSame(3, $report->stats->totalRegistrations);
        $this->assertSame(1, $report->stats->todayRegistrations);
        $this->assertSame(45.0, $report->stats->revenue);
        $this->assertSame(1, $report->stats->openCarts);
        $this->assertSame(
            "Marche des Amis, point du 24/10 à 19h45\nInscrits : 3 (+1 aujourd'hui)\nRecettes inscriptions : 45 €\nPaniers en cours : 1\nAnicka",
            $report->text,
        );
    }

    public function test_amounts_use_the_french_format(): void
    {
        $this->assertSame('1 234,50 €', EventDailyReportService::formatAmount(1234.5, 'EUR'));
        $this->assertSame('350 €', EventDailyReportService::formatAmount(350.0, 'EUR'));
    }

    public function test_report_leaves_once_per_local_day_when_the_local_time_is_reached(): void
    {
        $this->teamOf(self::RESPONSABLE, OrganizerTeamContactRole::ORGANIZER);
        $this->teamOf(self::COPY, OrganizerTeamContactRole::COPY);
        $service = app(EventDailyReportService::class);
        $service->configure($this->event->id, enabled: true, sendTime: '20:45');

        Carbon::setTestNow(Carbon::parse('2026-10-24 17:44:00', 'UTC'));
        $this->assertSame([], $service->sendDue());

        Carbon::setTestNow(Carbon::parse('2026-10-24 17:45:00', 'UTC'));
        $this->assertCount(1, $service->sendDue());
        $this->assertSame([self::RESPONSABLE, self::COPY], array_column($this->sent, 'to'));
        $this->assertStringStartsWith('Marche des Amis, point du 24/10 à 20h45', $this->sent[0]['content']);

        Carbon::setTestNow(Carbon::parse('2026-10-24 20:59:00', 'UTC'));
        $this->assertSame([], $service->sendDue());
        $this->assertSame('0', (string) Artisan::call('events:send-daily-reports'));
        $this->assertCount(2, $this->sent);

        Carbon::setTestNow(Carbon::parse('2026-10-25 17:46:00', 'UTC'));
        $this->assertCount(1, $service->sendDue());
        $this->assertCount(4, $this->sent);
        $this->assertSame('2026-10-25', substr((string) DB::table('event_daily_reports')->value('last_sent_on'), 0, 10));
    }

    public function test_a_day_already_claimed_is_never_sent_twice(): void
    {
        $this->teamOf(self::RESPONSABLE, OrganizerTeamContactRole::ORGANIZER);
        $service = app(EventDailyReportService::class);
        $report = $service->configure($this->event->id, enabled: true, sendTime: '19:00');

        DB::table('event_daily_reports')->where('id', $report->getId())->update(['last_sent_on' => '2026-10-24']);

        $this->assertSame([], $service->sendDue());
        $this->assertSame([], $this->sent);
        $this->assertFalse(EventDailyReportService::isDue(
            (new EventDailyReportDomainObject)->setEnabled(true)->setSendTime('19:00')->setTimezone('Indian/Mayotte')->setLastSentOn('2026-10-24'),
            Carbon::now('UTC'),
        ));
    }

    public function test_supervision_copy_is_optional_and_opted_out_numbers_are_skipped(): void
    {
        $this->teamOf(self::RESPONSABLE, OrganizerTeamContactRole::ORGANIZER);
        $this->teamOf(self::COPY, OrganizerTeamContactRole::COPY);
        DB::table('sms_opt_outs')->insert([
            'organizer_id' => $this->event->organizer_id,
            'phone' => self::COPY,
            'source' => 'KEYWORD',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $service = app(EventDailyReportService::class);

        $result = $service->sendNow($this->event->id);
        $this->assertSame([self::RESPONSABLE], array_column($this->sent, 'to'));
        $this->assertSame([self::COPY], $result->team->optedOut);
        $this->assertNull($result->supervision);

        $service->configure($this->event->id, copySupervisors: true);
        $service->sendNow($this->event->id);
        $this->assertSame([self::RESPONSABLE, self::RESPONSABLE, self::SUPERVISOR], array_column($this->sent, 'to'));
        $this->assertTrue((bool) DB::table('sms_messages')->where('content', 'like', 'Marche des Amis%')->orderByDesc('id')->value('is_supervision'));
    }

    public function test_sending_to_a_number_without_conversation_creates_it_on_the_organizer_line(): void
    {
        $this->teamOf(self::RESPONSABLE, OrganizerTeamContactRole::ORGANIZER);
        $this->assertSame(0, SmsConversation::query()->where('phone', self::RESPONSABLE)->count());

        app(EventDailyReportService::class)->sendNow($this->event->id);

        $conversation = SmsConversation::query()->where('phone', self::RESPONSABLE)->sole();
        $this->assertSame($this->event->organizer_id, $conversation->organizer_id);
        $this->assertTrue((bool) $conversation->is_team);
        $this->assertNotNull($conversation->line_settings_id);
        $this->assertSame(self::LINE_NUMBER, $this->sent[0]['from']);
    }

    public function test_dry_run_command_prints_the_text_without_sending(): void
    {
        $this->teamOf(self::RESPONSABLE, OrganizerTeamContactRole::ORGANIZER);

        $this->assertSame(0, Artisan::call('events:daily-report', ['event' => $this->event->id, '--dry-run' => true]));
        $this->assertStringContainsString('Recettes inscriptions : 0 €', Artisan::output());
        $this->assertSame([], $this->sent);

        $this->assertSame(0, Artisan::call('events:daily-report', ['event' => $this->event->id, '--enable' => true, '--time' => '20:45']));
        $row = DB::table('event_daily_reports')->where('event_id', $this->event->id)->first();
        $this->assertTrue((bool) $row->enabled);
        $this->assertSame('20:45', $row->send_time);
        $this->assertSame('Indian/Mayotte', $row->timezone);
        $this->assertFalse((bool) $row->copy_supervisors);
        $this->assertSame([], $this->sent);
    }

    private function teamOf(string $phone, OrganizerTeamContactRole $role): void
    {
        app(OrganizerTeamContactService::class)->save($this->event->organizer_id, $phone, null, $role);
    }

    /**
     * @return array{0: Product, 1: ProductPrice}
     */
    private function product(string $title, string $type, float $price, bool $isRefundProtection = false): array
    {
        $product = Product::create([
            'event_id' => $this->event->id,
            'title' => $title,
            'product_type' => $type,
            'type' => 'PAID',
            'order' => 2,
            'is_refund_protection' => $isRefundProtection,
        ]);

        return [$product, ProductPrice::create([
            'product_id' => $product->id,
            'price' => $price,
            'initial_quantity_available' => null,
            'quantity_sold' => 0,
            'order' => 1,
        ])];
    }

    private function order(
        string $status,
        int $tickets,
        int $protections,
        int $options,
        string $createdAt,
        ?string $refundStatus = null,
        string $attendeeStatus = 'ACTIVE',
        ?string $reservedUntil = null,
    ): void {
        $orderId = DB::table('orders')->insertGetId([
            'short_id' => 'o_'.Str::random(13),
            'public_id' => 'O-'.strtoupper(Str::random(7)),
            'event_id' => $this->event->id,
            'total_before_additions' => 15 * $tickets,
            'total_gross' => 15 * $tickets + 0.99 * $tickets,
            'total_fee' => 0.99 * $tickets,
            'currency' => 'EUR',
            'first_name' => 'Awa',
            'last_name' => 'Saïd',
            'email' => 'awa@example.test',
            'status' => $status,
            'refund_status' => $refundStatus,
            'reserved_until' => $reservedUntil,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        $lines = [[$this->ticket, $this->ticketPrice, 15.0, $tickets], [$this->protection, $this->protectionPrice, 0.49, $protections], [$this->option, $this->optionPrice, 10.0, $options]];
        foreach ($lines as [$product, $price, $amount, $quantity]) {
            if ($quantity === 0) {
                continue;
            }

            DB::table('order_items')->insert([
                'order_id' => $orderId,
                'product_id' => $product->id,
                'product_price_id' => $price->id,
                'item_name' => $product->title,
                'quantity' => $quantity,
                'price' => $amount,
                'price_before_discount' => $amount,
                'total_before_additions' => $amount * $quantity,
                'total_service_fee' => $product->id === $this->ticket->id ? 0.99 * $quantity : 0,
                'total_tax' => 0,
                'total_gross' => $amount * $quantity,
                'product_type' => $product->product_type,
            ]);

            if ($product->product_type !== 'TICKET') {
                continue;
            }

            for ($i = 0; $i < $quantity; $i++) {
                DB::table('attendees')->insert([
                    'short_id' => 'a_'.Str::random(13),
                    'public_id' => 'A-'.strtoupper(Str::random(7)),
                    'first_name' => 'Awa',
                    'last_name' => 'Saïd',
                    'email' => 'awa@example.test',
                    'order_id' => $orderId,
                    'product_id' => $product->id,
                    'product_price_id' => $price->id,
                    'event_id' => $this->event->id,
                    'status' => $status === 'COMPLETED' ? $attendeeStatus : 'AWAITING_PAYMENT',
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ]);
            }
        }
    }
}
