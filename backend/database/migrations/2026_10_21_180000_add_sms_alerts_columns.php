<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizer_sms_settings', function (Blueprint $table) {
            $table->boolean('notify_on_inbound_sms')->default(true);
            $table->string('phone_status', 10)->nullable();
            $table->timestamp('phone_status_changed_at')->nullable();
        });

        Schema::table('sms_conversations', function (Blueprint $table) {
            $table->timestamp('inbound_notified_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sms_conversations', function (Blueprint $table) {
            $table->dropColumn('inbound_notified_at');
        });

        Schema::table('organizer_sms_settings', function (Blueprint $table) {
            $table->dropColumn(['notify_on_inbound_sms', 'phone_status', 'phone_status_changed_at']);
        });
    }
};
