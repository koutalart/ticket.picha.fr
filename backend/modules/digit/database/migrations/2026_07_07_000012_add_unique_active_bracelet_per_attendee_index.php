<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * DB-enforced guarantee: an attendee can have at most one *active* bracelet
 * at a time. "Active" excludes REVOKED and COMPROMISED - a revoked/replaced
 * bracelet must not block associating a replacement to the same attendee.
 *
 * Partial unique index (not a plain unique constraint) for the same reason
 * attendee_check_ins uses one (see
 * 2026_07_18_000001_add_unique_constraint_to_attendee_check_ins.php):
 * we need the constraint to apply only to "currently active" rows.
 *
 * CREATE INDEX CONCURRENTLY cannot run inside a transaction block - same
 * withinTransaction=false requirement as the check-ins migration.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement(<<<SQL
            CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS digit_bracelets_unique_active_attendee
            ON digit_bracelets (attendee_id)
            WHERE attendee_id IS NOT NULL AND status NOT IN ('REVOKED', 'COMPROMISED')
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS digit_bracelets_unique_active_attendee');
    }
};
