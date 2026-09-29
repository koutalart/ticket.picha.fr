<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Last Zebra printer host that successfully printed, per (user, event), so a
 * Kiosk station whose localStorage was wiped (private browsing, new device)
 * can pre-fill it. Written only after a successful print.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('box_office_printer_preferences', static function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('printer_host', 45);
            $table->timestamps();

            $table->unique(['user_id', 'event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('box_office_printer_preferences');
    }
};
