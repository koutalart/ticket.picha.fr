<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Report\Reports;

use HiEvents\DomainObjects\Enums\ProductType;
use HiEvents\DomainObjects\Enums\QrLinkVisitType;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Services\Domain\Report\AbstractReportService;
use Illuminate\Support\Carbon;

/**
 * Completed orders of an event grouped by where the buyer came from: QR code or SMS campaign link,
 * utm campaign, referring site, or direct. Visits are known for QR codes and SMS links only.
 */
class SalesBySourceReport extends AbstractReportService
{
    public const CHANNEL_QR = 'qr';

    public const CHANNEL_SMS = 'sms';

    public const CHANNEL_CAMPAIGN = 'campaign';

    public const CHANNEL_REFERRAL = 'referral';

    public const CHANNEL_DIRECT = 'direct';

    protected function getSqlQuery(Carbon $startDate, Carbon $endDate): string
    {
        $start = $startDate->copy()->utc()->format('Y-m-d H:i:s');
        $end = $endDate->copy()->utc()->format('Y-m-d H:i:s');
        $completed = OrderStatus::COMPLETED->name;
        $ticket = ProductType::TICKET->name;
        $scan = QrLinkVisitType::SCAN->value;
        $qr = self::CHANNEL_QR;
        $sms = self::CHANNEL_SMS;
        $campaign = self::CHANNEL_CAMPAIGN;
        $referral = self::CHANNEL_REFERRAL;
        $direct = self::CHANNEL_DIRECT;
        $touch = "COALESCE(o.tracking_context->'last_touch', o.tracking_context->'first_touch')";

        return <<<SQL
            WITH order_sources AS (
                SELECT
                    o.id,
                    o.total_gross,
                    (
                        SELECT COALESCE(SUM(oi.quantity), 0)
                        FROM order_items oi
                        JOIN products p ON p.id = oi.product_id
                        WHERE oi.order_id = o.id
                          AND oi.deleted_at IS NULL
                          AND p.product_type = '$ticket'
                    ) AS tickets,
                    ql.id AS qr_link_id,
                    ql.name AS qr_link_name,
                    sc.id AS sms_campaign_id,
                    sc.name AS sms_campaign_name,
                    NULLIF(TRIM($touch->>'utm_source'), '') AS utm_source,
                    NULLIF(TRIM($touch->>'utm_medium'), '') AS utm_medium,
                    NULLIF(TRIM($touch->>'utm_campaign'), '') AS utm_campaign,
                    NULLIF(LOWER(TRIM($touch->>'referrer_domain')), '') AS referrer_domain
                FROM orders o
                LEFT JOIN qr_links ql ON ql.id = o.qr_link_id
                LEFT JOIN sms_campaigns sc ON sc.id = ql.sms_campaign_id
                WHERE o.deleted_at IS NULL
                  AND o.status = '$completed'
                  AND o.event_id = :event_id
                  AND o.created_at BETWEEN '$start' AND '$end'
            ),
            classified AS (
                SELECT
                    *,
                    CASE
                        WHEN sms_campaign_id IS NOT NULL THEN 'sms:' || sms_campaign_id
                        WHEN qr_link_id IS NOT NULL THEN 'qr:' || qr_link_id
                        WHEN utm_source IS NOT NULL OR utm_medium IS NOT NULL OR utm_campaign IS NOT NULL
                            THEN 'utm:' || COALESCE(utm_source, '') || '|' || COALESCE(utm_medium, '') || '|' || COALESCE(utm_campaign, '')
                        WHEN referrer_domain IS NOT NULL THEN 'ref:' || referrer_domain
                        ELSE '$direct'
                    END AS source_key,
                    CASE
                        WHEN sms_campaign_id IS NOT NULL THEN '$sms'
                        WHEN qr_link_id IS NOT NULL THEN '$qr'
                        WHEN utm_source IS NOT NULL OR utm_medium IS NOT NULL OR utm_campaign IS NOT NULL THEN '$campaign'
                        WHEN referrer_domain IS NOT NULL THEN '$referral'
                        ELSE '$direct'
                    END AS channel,
                    CASE
                        WHEN sms_campaign_id IS NOT NULL THEN sms_campaign_name
                        WHEN qr_link_id IS NOT NULL THEN qr_link_name
                        WHEN utm_source IS NOT NULL OR utm_medium IS NOT NULL OR utm_campaign IS NOT NULL THEN utm_source
                        ELSE referrer_domain
                    END AS source
                FROM order_sources
            ),
            sales AS (
                SELECT
                    source_key,
                    MIN(channel) AS channel,
                    MIN(source) AS source,
                    CASE WHEN MIN(channel) = '$campaign' THEN MIN(utm_medium) END AS utm_medium,
                    CASE WHEN MIN(channel) = '$campaign' THEN MIN(utm_campaign) END AS utm_campaign,
                    COUNT(*) AS orders_count,
                    SUM(tickets) AS tickets_count,
                    SUM(total_gross) AS revenue
                FROM classified
                GROUP BY source_key
            ),
            visits AS (
                SELECT
                    CASE WHEN ql.sms_campaign_id IS NOT NULL THEN 'sms:' || ql.sms_campaign_id ELSE 'qr:' || ql.id END AS source_key,
                    CASE WHEN ql.sms_campaign_id IS NOT NULL THEN '$sms' ELSE '$qr' END AS channel,
                    MIN(CASE WHEN ql.sms_campaign_id IS NOT NULL THEN sc.name ELSE ql.name END) AS source,
                    COUNT(v.id) AS visits_count
                FROM qr_links ql
                JOIN qr_link_visits v ON v.qr_link_id = ql.id
                    AND v.type = '$scan'
                    AND v.created_at BETWEEN '$start' AND '$end'
                LEFT JOIN sms_campaigns sc ON sc.id = ql.sms_campaign_id
                WHERE ql.event_id = :event_id
                GROUP BY 1, 2
            )
            SELECT
                COALESCE(s.channel, v.channel) AS channel,
                COALESCE(s.source, v.source) AS source,
                s.utm_medium,
                s.utm_campaign,
                COALESCE(s.orders_count, 0) AS orders_count,
                COALESCE(s.tickets_count, 0) AS tickets_count,
                COALESCE(s.revenue, 0) AS revenue,
                v.visits_count,
                CASE
                    WHEN v.visits_count > 0 THEN ROUND(100.0 * COALESCE(s.orders_count, 0) / v.visits_count, 1)
                END AS conversion_rate
            FROM sales s
            FULL OUTER JOIN visits v ON v.source_key = s.source_key
            ORDER BY revenue DESC, orders_count DESC, v.visits_count DESC NULLS LAST, source
        SQL;
    }
}
