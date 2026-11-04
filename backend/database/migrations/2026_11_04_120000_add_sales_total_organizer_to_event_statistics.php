<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PICHA: organizer sales = what the buyer paid minus the service fees (platform fee) and the cancellation
 * protection, which are PICHA revenue, minus what was refunded. sales_total_gross is left untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_statistics', function (Blueprint $table) {
            $table->decimal('sales_total_organizer', 14, 2)->default(0);
        });

        Schema::table('event_daily_statistics', function (Blueprint $table) {
            $table->decimal('sales_total_organizer', 14, 2)->default(0);
        });

        $orderShares = <<<SQL
            SELECT
                o.event_id,
                o.created_at::date AS day,
                GREATEST(0, o.total_gross - o.total_fee - COALESCE(protection.total, 0) - o.total_refunded) AS organizer
            FROM orders o
            LEFT JOIN (
                SELECT oi.order_id, SUM(COALESCE(oi.total_gross, 0)) AS total
                FROM order_items oi
                INNER JOIN products p ON p.id = oi.product_id
                WHERE p.is_refund_protection = true
                  AND oi.deleted_at IS NULL
                GROUP BY oi.order_id
            ) protection ON protection.order_id = o.id
            WHERE o.deleted_at IS NULL
              AND (o.status = 'COMPLETED' OR (o.status = 'CANCELLED' AND o.payment_status = 'PAYMENT_RECEIVED'))
        SQL;

        DB::statement(<<<SQL
            UPDATE event_statistics es
            SET sales_total_organizer = GREATEST(0, LEAST(shares.total, es.sales_total_gross))
            FROM (
                SELECT event_id, SUM(organizer) AS total
                FROM ($orderShares) order_shares
                GROUP BY event_id
            ) shares
            WHERE shares.event_id = es.event_id
        SQL);

        DB::statement(<<<SQL
            UPDATE event_daily_statistics eds
            SET sales_total_organizer = GREATEST(0, LEAST(shares.total, eds.sales_total_gross))
            FROM (
                SELECT event_id, day, SUM(organizer) AS total
                FROM ($orderShares) order_shares
                GROUP BY event_id, day
            ) shares
            WHERE shares.event_id = eds.event_id
              AND shares.day = eds.date
        SQL);
    }

    public function down(): void
    {
        Schema::table('event_statistics', function (Blueprint $table) {
            $table->dropColumn('sales_total_organizer');
        });

        Schema::table('event_daily_statistics', function (Blueprint $table) {
            $table->dropColumn('sales_total_organizer');
        });
    }
};
