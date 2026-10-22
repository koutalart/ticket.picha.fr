<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('organizer_sms_settings', function (Blueprint $table) {
            $table->boolean('assistant_auto_send')->default(false);
            $table->text('assistant_holding_reply')->nullable();
        });

        Schema::table('sms_messages', function (Blueprint $table) {
            $table->foreignId('auto_reply_to_message_id')->nullable()->unique()->constrained('sms_messages')->nullOnDelete();
            $table->string('auto_reply_type', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sms_messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('auto_reply_to_message_id');
            $table->dropColumn('auto_reply_type');
        });

        Schema::table('organizer_sms_settings', function (Blueprint $table) {
            $table->dropColumn(['assistant_auto_send', 'assistant_holding_reply']);
        });
    }
};
