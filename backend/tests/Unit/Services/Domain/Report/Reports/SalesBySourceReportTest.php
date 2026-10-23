<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Domain\Report\Reports;

use HiEvents\DomainObjects\Enums\ReportTypes;
use HiEvents\Helper\IdHelper;
use HiEvents\Models\Event;
use HiEvents\Models\Order;
use HiEvents\Models\OrderItem;
use HiEvents\Models\Product;
use HiEvents\Models\ProductPrice;
use HiEvents\Models\QrLink;
use HiEvents\Services\Domain\Report\Factory\ReportServiceFactory;
use HiEvents\Services\Domain\Report\Reports\SalesBySourceReport;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Support\BoxOfficeTestFixtures;
use Tests\TestCase;

class SalesBySourceReportTest extends TestCase
{
    use BoxOfficeTestFixtures;
    use DatabaseTransactions;

    private Event $event;

    private Product $product;

    private ProductPrice $productPrice;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->event, $this->product, $this->productPrice] = $this->createEventWithProduct();
    }

    public function test_factory_creates_the_report(): void
    {
        self::assertInstanceOf(
            SalesBySourceReport::class,
            $this->app->make(ReportServiceFactory::class)->create(ReportTypes::SALES_BY_SOURCE_REPORT),
        );
    }

    public function test_orders_are_grouped_by_qr_code_sms_campaign_utm_referrer_and_direct(): void
    {
        $poster = $this->qrLink('affiche1', 'Affiche marché');
        $smsCampaignId = DB::table('sms_campaigns')->insertGetId([
            'account_id' => $this->event->account_id,
            'organizer_id' => $this->event->organizer_id,
            'event_id' => $this->event->id,
            'name' => 'Relance J-7',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $smsOne = $this->qrLink('smsaaaa1', 'Fatima', ['sms_campaign_id' => $smsCampaignId]);
        $smsTwo = $this->qrLink('smsaaaa2', 'Anli', ['sms_campaign_id' => $smsCampaignId]);
        $unsold = $this->qrLink('flyer001', 'Flyer plage');

        $this->visits($poster, 4);
        $this->visits($smsOne, 1);
        $this->visits($smsTwo, 1);
        $this->visits($unsold, 3);

        $this->order(30, 2, ['last_touch' => ['utm_source' => 'facebook']], $poster);
        $this->order(15, 1, null, $smsOne);
        $this->order(15, 1, null, $smsTwo);
        $this->order(45, 3, ['first_touch' => ['utm_source' => 'google'], 'last_touch' => ['utm_source' => 'facebook', 'utm_medium' => 'paid', 'utm_campaign' => 'tournee']]);
        $this->order(15, 1, ['last_touch' => ['utm_source' => 'facebook', 'utm_medium' => 'paid', 'utm_campaign' => 'tournee']]);
        $this->order(20, 1, ['last_touch' => ['referrer_domain' => 'instagram.com']]);
        $this->order(10, 1, ['first_touch' => ['landing_path' => '/event/1/x']]);
        $this->order(99, 4, ['last_touch' => ['utm_source' => 'facebook']], null, 'CANCELLED');

        $rows = $this->app->make(SalesBySourceReport::class)
            ->generateReport($this->event->id)
            ->map(fn ($row) => (array) $row)
            ->keyBy(fn (array $row) => $row['channel'].':'.($row['source'] ?? ''));

        self::assertCount(6, $rows);

        $campaign = $rows['campaign:facebook'];
        self::assertSame(2, (int) $campaign['orders_count']);
        self::assertSame(4, (int) $campaign['tickets_count']);
        self::assertEquals(60, (float) $campaign['revenue']);
        self::assertSame('paid', $campaign['utm_medium']);
        self::assertSame('tournee', $campaign['utm_campaign']);
        self::assertNull($campaign['visits_count']);

        $qr = $rows['qr:Affiche marché'];
        self::assertSame(1, (int) $qr['orders_count']);
        self::assertSame(2, (int) $qr['tickets_count']);
        self::assertSame(4, (int) $qr['visits_count']);
        self::assertEquals(25.0, (float) $qr['conversion_rate']);

        $sms = $rows['sms:Relance J-7'];
        self::assertSame(2, (int) $sms['orders_count']);
        self::assertSame(2, (int) $sms['visits_count']);
        self::assertEquals(30, (float) $sms['revenue']);

        $flyer = $rows['qr:Flyer plage'];
        self::assertSame(0, (int) $flyer['orders_count']);
        self::assertSame(3, (int) $flyer['visits_count']);
        self::assertEquals(0.0, (float) $flyer['conversion_rate']);

        self::assertSame(1, (int) $rows['referral:instagram.com']['orders_count']);
        self::assertSame(1, (int) $rows['direct:']['orders_count']);
        self::assertEquals(10, (float) $rows['direct:']['revenue']);
    }

    private function qrLink(string $shortId, string $name, array $attributes = []): QrLink
    {
        return QrLink::create(array_merge([
            'account_id' => $this->event->account_id,
            'organizer_id' => $this->event->organizer_id,
            'event_id' => $this->event->id,
            'short_id' => $shortId,
            'name' => $name,
            'destination_type' => 'EVENT',
            'status' => 'ACTIVE',
        ], $attributes));
    }

    private function visits(QrLink $qrLink, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            DB::table('qr_link_visits')->insert([
                'qr_link_id' => $qrLink->id,
                'type' => 'SCAN',
                'visitor_hash' => hash('sha256', $qrLink->short_id.$i),
                'is_unique' => true,
                'created_at' => now()->subHour(),
            ]);
        }

        DB::table('qr_link_visits')->insert([
            'qr_link_id' => $qrLink->id,
            'type' => 'CLICK',
            'visitor_hash' => hash('sha256', $qrLink->short_id.'click'),
            'created_at' => now()->subHour(),
        ]);
    }

    private function order(float $total, int $tickets, ?array $trackingContext, ?QrLink $qrLink = null, string $status = 'COMPLETED'): void
    {
        $order = Order::create([
            'event_id' => $this->event->id,
            'total_gross' => $total,
            'total_refunded' => 0,
            'currency' => 'EUR',
            'status' => $status,
            'payment_status' => 'PAYMENT_RECEIVED',
            'email' => 'acheteur@example.test',
            'first_name' => 'Anli',
            'last_name' => 'Madi',
            'short_id' => IdHelper::shortId(IdHelper::ORDER_PREFIX),
            'public_id' => IdHelper::publicId(IdHelper::ORDER_PREFIX),
            'qr_link_id' => $qrLink?->id,
            'tracking_context' => $trackingContext,
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $this->product->id,
            'product_price_id' => $this->productPrice->id,
            'quantity' => $tickets,
            'price' => $total / $tickets,
            'total_before_additions' => $total,
            'item_name' => 'Entrée',
            'product_type' => 'TICKET',
        ]);
    }
}
