<?php

declare(strict_types=1);

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\SmsCampaignDomainObject;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\DomainObjects\Status\SmsCampaignRecipientStatus;
use HiEvents\Models\SmsCampaign;
use HiEvents\Models\SmsCampaignRecipient;
use HiEvents\Repository\Interfaces\SmsCampaignRepositoryInterface;
use HiEvents\Services\Domain\Sms\DTO\SmsCampaignStatsDTO;
use Illuminate\Support\Facades\DB;

/**
 * @extends BaseRepository<SmsCampaignDomainObject>
 */
class SmsCampaignRepository extends BaseRepository implements SmsCampaignRepositoryInterface
{
    protected function getModel(): string
    {
        return SmsCampaign::class;
    }

    public function getDomainObject(): string
    {
        return SmsCampaignDomainObject::class;
    }

    public function getStatsByCampaignIds(array $campaignIds): array
    {
        if ($campaignIds === []) {
            return [];
        }

        $sent = SmsCampaignRecipientStatus::SENT->value;
        $delivered = SmsCampaignRecipientStatus::DELIVERED->value;
        $failed = [SmsCampaignRecipientStatus::FAILED->value, SmsCampaignRecipientStatus::EXPIRED->value];

        $recipients = SmsCampaignRecipient::query()
            ->leftJoin('qr_links', 'qr_links.id', '=', 'sms_campaign_recipients.qr_link_id')
            ->whereIn('sms_campaign_recipients.sms_campaign_id', $campaignIds)
            ->groupBy('sms_campaign_recipients.sms_campaign_id')
            ->select(
                'sms_campaign_recipients.sms_campaign_id',
                DB::raw('COUNT(*) as recipients'),
                DB::raw("SUM(CASE WHEN sms_campaign_recipients.status = '$sent' THEN 1 ELSE 0 END) as sent"),
                DB::raw("SUM(CASE WHEN sms_campaign_recipients.status = '$delivered' THEN 1 ELSE 0 END) as delivered"),
                DB::raw("SUM(CASE WHEN sms_campaign_recipients.status IN ('$failed[0]', '$failed[1]') THEN 1 ELSE 0 END) as failed"),
                DB::raw('COALESCE(SUM(qr_links.scans_count), 0) as visits'),
                DB::raw('SUM(CASE WHEN qr_links.unique_scans_count > 0 THEN 1 ELSE 0 END) as clickers'),
            )
            ->get()
            ->keyBy('sms_campaign_id');

        $orders = SmsCampaignRecipient::query()
            ->join('orders', 'orders.qr_link_id', '=', 'sms_campaign_recipients.qr_link_id')
            ->whereIn('sms_campaign_recipients.sms_campaign_id', $campaignIds)
            ->where('orders.status', OrderStatus::COMPLETED->name)
            ->whereNull('orders.deleted_at')
            ->groupBy('sms_campaign_recipients.sms_campaign_id')
            ->select(
                'sms_campaign_recipients.sms_campaign_id',
                DB::raw('COUNT(*) as orders'),
                DB::raw('COALESCE(SUM(orders.total_gross), 0) as revenue_gross'),
            )
            ->get()
            ->keyBy('sms_campaign_id');

        $stats = [];
        foreach ($campaignIds as $campaignId) {
            $row = $recipients->get($campaignId);
            $orderRow = $orders->get($campaignId);
            $total = (int) ($row->recipients ?? 0);
            $sentCount = (int) ($row->sent ?? 0);
            $deliveredCount = (int) ($row->delivered ?? 0);
            $failedCount = (int) ($row->failed ?? 0);

            $stats[(int) $campaignId] = new SmsCampaignStatsDTO(
                recipients: $total,
                pending: max(0, $total - $sentCount - $deliveredCount - $failedCount),
                sent: $sentCount,
                delivered: $deliveredCount,
                failed: $failedCount,
                visits: (int) ($row->visits ?? 0),
                clickers: (int) ($row->clickers ?? 0),
                orders: (int) ($orderRow->orders ?? 0),
                revenueGross: round((float) ($orderRow->revenue_gross ?? 0), 2),
            );
        }

        return $stats;
    }
}
