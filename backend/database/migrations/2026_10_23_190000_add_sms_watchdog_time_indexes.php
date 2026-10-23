<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sms_conversations', function (Blueprint $table) {
            $table->index(['organizer_id', 'draft_generated_at'], 'sms_conversations_organizer_draft_generated_at');
        });

        Schema::table('sms_registration_confirmations', function (Blueprint $table) {
            $table->index(['organizer_id', 'created_at'], 'sms_registration_confirmations_organizer_created_at');
        });

        Schema::table('sms_watchdog_alerts', function (Blueprint $table) {
            $table->index('anomaly_key');
            $table->index('alerted_at');
        });
    }

    public function down(): void
    {
        Schema::table('sms_watchdog_alerts', function (Blueprint $table) {
            $table->dropIndex(['anomaly_key']);
            $table->dropIndex(['alerted_at']);
        });

        Schema::table('sms_registration_confirmations', function (Blueprint $table) {
            $table->dropIndex('sms_registration_confirmations_organizer_created_at');
        });

        Schema::table('sms_conversations', function (Blueprint $table) {
            $table->dropIndex('sms_conversations_organizer_draft_generated_at');
        });
    }
};
