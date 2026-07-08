<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Physical QR wristbands. A bracelet only ever resolves an opaque `code` to
 * an attendee_id - it carries no business data itself (category, event name,
 * etc. all stay native, read via attendee->product / attendee->event).
 *
 * No FK constraints toward native Hi.Events tables (event_id, account_id,
 * attendee_id) - same convention already used by digit_scan_devices: keeps
 * this module's migrations fully independent of native schema/migration
 * ordering, at the cost of the relation being enforced in application code
 * rather than by the database. Referential correctness of attendee_id is
 * additionally covered by the partial unique index in the next migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('digit_bracelets', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->unsignedBigInteger('event_id');
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('attendee_id')->nullable();
            $table->string('batch_label')->nullable();
            $table->string('status')->default('GENERATED');
            $table->timestamp('printed_at')->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('compromised_at')->nullable();
            $table->timestamps();

            $table->index('event_id');
            $table->index('attendee_id');
            $table->index('status');
            $table->index('batch_label');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('digit_bracelets');
    }
};
