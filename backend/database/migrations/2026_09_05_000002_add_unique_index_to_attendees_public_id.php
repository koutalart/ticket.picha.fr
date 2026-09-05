<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * S4 (PICHA_BOX_OFFICE_SECURITY_FINDINGS.md): attendees.public_id had no
 * uniqueness constraint at the DB level — only orders.public_id did.
 * IdHelper::publicId() generates a random string with no collision check,
 * and the QR code printed on a ticket encodes exactly this value, so a
 * collision would let one ticket's QR check in as another's attendee.
 *
 * Partial index (WHERE deleted_at IS NULL), same reasoning as
 * modules/digit/database/migrations/2026_07_18_000001_add_unique_constraint_to_attendee_check_ins.php:
 * attendees uses SoftDeletes — a plain unique index would block reuse of a
 * public_id after a soft delete.
 */
return new class extends Migration {
    /**
     * CREATE INDEX CONCURRENTLY cannot run inside a transaction block.
     * This MUST be a public property — Laravel's Migrator checks it as a
     * property, not a method (see the DIGIT migration referenced above,
     * whose own comment documents this exact trap).
     */
    public $withinTransaction = false;

    public function up(): void
    {
        $duplicates = DB::table('attendees')
            ->select('public_id')
            ->whereNull('deleted_at')
            ->groupBy('public_id')
            ->havingRaw('count(*) > 1')
            ->pluck('public_id');

        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException(sprintf(
                'Cannot create a unique index on attendees.public_id: %d duplicate value(s) already exist ' .
                'among non-deleted attendees (%s). Resolve these manually (rename or soft-delete the ' .
                'duplicates) before re-running this migration.',
                $duplicates->count(),
                $duplicates->implode(', '),
            ));
        }

        DB::statement(<<<SQL
            CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS attendees_public_id_unique_active
            ON attendees (public_id)
            WHERE deleted_at IS NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS attendees_public_id_unique_active');
    }
};
