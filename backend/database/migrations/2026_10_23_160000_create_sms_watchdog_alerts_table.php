<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sms_watchdog_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organizer_id')->constrained('organizers')->cascadeOnDelete();
            $table->string('anomaly_type', 40);
            $table->string('anomaly_key', 191);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('alerted_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(['anomaly_type', 'anomaly_key']);
            $table->index(['organizer_id', 'resolved_at']);
        });

        Schema::table('sms_messages', function (Blueprint $table) {
            $table->index(['organizer_id', 'created_at'], 'sms_messages_organizer_created_at');
        });
    }

    public function down(): void
    {
        Schema::table('sms_messages', function (Blueprint $table) {
            $table->dropIndex('sms_messages_organizer_created_at');
        });

        Schema::dropIfExists('sms_watchdog_alerts');
    }
};
