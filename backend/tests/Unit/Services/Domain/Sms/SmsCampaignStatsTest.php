<?php

namespace Tests\Unit\Services\Domain\Sms;

use HiEvents\DomainObjects\QrLinkDomainObject;
use HiEvents\DomainObjects\SmsCampaignDomainObject;
use HiEvents\DomainObjects\Status\SmsCampaignRecipientStatus;
use HiEvents\Helper\IdHelper;
use HiEvents\Models\Event;
use HiEvents\Models\Order;
use HiEvents\Models\QrCampaign;
use HiEvents\Models\QrLink;
use HiEvents\Models\SmsCampaignRecipient;
use HiEvents\Services\Application\Handlers\QrLink\GetQrLinksHandler;
use HiEvents\Services\Application\Handlers\Sms\GetSmsCampaignRecipientsHandler;
use HiEvents\Services\Application\Handlers\Sms\GetSmsCampaignsHandler;
use HiEvents\Services\Domain\Sms\DTO\SmsCampaignImportDTO;
use HiEvents\Services\Domain\Sms\DTO\SmsCampaignImportRecipientDTO;
use HiEvents\Services\Domain\Sms\SmsCampaignDeliveryService;
use HiEvents\Services\Domain\Sms\SmsCampaignImportService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Support\BoxOfficeTestFixtures;
use Tests\TestCase;

class SmsCampaignStatsTest extends TestCase
{
    use BoxOfficeTestFixtures;
    use DatabaseTransactions;

    private Event $event;

    private QrCampaign $qrCampaign;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->event] = $this->createEventWithProduct();

        $this->qrCampaign = QrCampaign::create([
            'account_id' => $this->event->account_id,
            'organizer_id' => $this->event->organizer_id,
            'name' => 'SMS Halloween 21/10',
        ]);

        $this->qrLink('smsaaaa1', $this->qrCampaign->id, scans: 3, uniqueScans: 1);
        $this->qrLink('smsbbbb2', $this->qrCampaign->id);
        $this->qrLink('affiche1', null, scans: 9, uniqueScans: 7);

        $this->order('smsaaaa1', 40.00, 'COMPLETED');
        $this->order('smsaaaa1', 25.00, 'RESERVED');
    }

    public function test_import_moves_the_links_out_of_the_qr_codes_tab(): void
    {
        $result = $this->import();

        self::assertSame(3, $result->created);
        self::assertSame(2, $result->linkedQrLinks);
        self::assertCount(2, $result->warnings);
        self::assertTrue($result->qrCampaignRetired);
        self::assertSoftDeleted('qr_campaigns', ['id' => $this->qrCampaign->id]);

        $qrTab = app(GetQrLinksHandler::class)->handle($this->event->organizer_id);
        self::assertSame(['affiche1'], $qrTab->map(fn (QrLinkDomainObject $link) => $link->getShortId())->all());
    }

    public function test_campaign_stats_count_real_clicks_and_completed_orders(): void
    {
        $this->import();

        /** @var SmsCampaignDomainObject $campaign */
        $campaign = app(GetSmsCampaignsHandler::class)->handle($this->event->organizer_id)->first();
        $stats = $campaign->getStats();

        self::assertSame('SMS Halloween 21/10', $campaign->getName());
        self::assertSame(3, $stats->recipients);
        self::assertSame(1, $stats->delivered);
        self::assertSame(1, $stats->failed);
        self::assertSame(1, $stats->pending);
        self::assertSame(3, $stats->visits);
        self::assertSame(1, $stats->clickers);
        self::assertSame(1, $stats->orders);
        self::assertSame(40.0, $stats->revenueGross);

        $recipients = app(GetSmsCampaignRecipientsHandler::class)->handle($this->event->organizer_id, $campaign->getId());
        $fatima = $recipients->first(fn ($recipient) => $recipient->getContactName() === 'Fatima');
        self::assertSame(1, $fatima->getQrLink()->getOrdersCount());
        self::assertSame(1, $fatima->getQrLink()->getUniqueScansCount());
    }

    public function test_reimport_refreshes_statuses_without_going_backwards(): void
    {
        $first = $this->import();

        $second = app(SmsCampaignImportService::class)->import(new SmsCampaignImportDTO(
            organizerId: $this->event->organizer_id,
            name: 'SMS Halloween 21/10',
            recipients: [
                new SmsCampaignImportRecipientDTO(phone: '0639 11 11 11', shortId: 'smsaaaa1', status: 'sent'),
                new SmsCampaignImportRecipientDTO(phone: '+262639333333', status: 'delivered'),
            ],
        ));

        self::assertSame($first->campaignId, $second->campaignId);
        self::assertSame(0, $second->created);
        self::assertSame(1, $second->updated);
        self::assertSame('DELIVERED', SmsCampaignRecipient::where('phone', '+262639111111')->value('status'));
        self::assertSame('DELIVERED', SmsCampaignRecipient::where('phone', '+262639333333')->value('status'));
    }

    public function test_webhook_status_only_applies_to_the_organizer_campaign(): void
    {
        $this->import();
        $service = app(SmsCampaignDeliveryService::class);

        self::assertFalse($service->applyProviderStatus([$this->event->organizer_id + 1], 'prov-3', SmsCampaignRecipientStatus::DELIVERED));
        self::assertTrue($service->applyProviderStatus([$this->event->organizer_id], 'prov-3', SmsCampaignRecipientStatus::DELIVERED, null, '2026-10-21T09:00:00Z'));

        $recipient = SmsCampaignRecipient::where('provider_message_id', 'prov-3')->first();
        self::assertSame('DELIVERED', $recipient->status);
        self::assertSame('2026-10-21 09:00:00', (string) $recipient->delivered_at);
    }

    private function import()
    {
        return app(SmsCampaignImportService::class)->import(new SmsCampaignImportDTO(
            organizerId: $this->event->organizer_id,
            name: 'SMS Halloween 21/10',
            recipients: [
                new SmsCampaignImportRecipientDTO(phone: '0639111111', contactName: 'Fatima', shortId: 'SMSAAAA1', providerMessageId: 'prov-1', status: 'delivered'),
                new SmsCampaignImportRecipientDTO(phone: '+262 639 22 22 22', contactName: 'Anli', shortId: 'smsbbbb2', providerMessageId: 'prov-2', status: 'failed', failureReason: 'Number unreachable'),
                new SmsCampaignImportRecipientDTO(phone: '0639333333', contactName: 'Zaina', shortId: 'inconnu1', providerMessageId: 'prov-3', status: 'scheduled'),
                new SmsCampaignImportRecipientDTO(phone: '12'),
            ],
            eventId: $this->event->id,
            retireQrCampaignId: $this->qrCampaign->id,
        ));
    }

    private function qrLink(string $shortId, ?int $campaignId, int $scans = 0, int $uniqueScans = 0): QrLink
    {
        return QrLink::create([
            'account_id' => $this->event->account_id,
            'organizer_id' => $this->event->organizer_id,
            'event_id' => $this->event->id,
            'qr_campaign_id' => $campaignId,
            'short_id' => $shortId,
            'name' => $shortId,
            'destination_type' => 'EVENT',
            'status' => 'ACTIVE',
            'scans_count' => $scans,
            'unique_scans_count' => $uniqueScans,
        ]);
    }

    private function order(string $shortId, float $total, string $status): void
    {
        Order::create([
            'event_id' => $this->event->id,
            'qr_link_id' => QrLink::where('short_id', $shortId)->value('id'),
            'total_gross' => $total,
            'currency' => 'EUR',
            'status' => $status,
            'email' => 'acheteur@example.test',
            'first_name' => 'Anli',
            'last_name' => 'Madi',
            'short_id' => IdHelper::shortId(IdHelper::ORDER_PREFIX),
            'public_id' => IdHelper::publicId(IdHelper::ORDER_PREFIX),
        ]);
    }
}
