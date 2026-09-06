<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D23 (PICHA_KIOSK_V2_DECISIONS.md) — per-event scope for the box office
 * operator account. An operator is a real users row (role
 * BOX_OFFICE_OPERATOR on account_users); this table says which event(s)
 * they may sell for. One row per (event, user); revocation flips status,
 * the row (and the users/account_users rows) is kept for audit-trail
 * integrity of past box_office_sales.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('event_box_office_operators', static function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('created_by_user_id')->constrained('users');
            $table->string('status')->default('ACTIVE');
            $table->timestamps();

            $table->unique(['event_id', 'user_id']);
            $table->index('user_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_box_office_operators');
    }
};
