<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * FINAL, unconditional source-of-truth protection against double check-in.
 * Enforced by Postgres regardless of Redis availability or application code.
 * Partial index (WHERE deleted_at IS NULL) because attendee_check_ins uses
 * SoftDeletes — a plain unique index would block a legitimate re-check-in
 * after an undo (soft delete).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<SQL
            CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS attendee_check_ins_unique_live_scan
            ON attendee_check_ins (attendee_id, check_in_list_id)
            WHERE deleted_at IS NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS attendee_check_ins_unique_live_scan');
    }

    /**
     * CREATE INDEX CONCURRENTLY cannot run inside a transaction block.
     */
    public function withinTransaction(): bool
    {
        return false;
    }
};
