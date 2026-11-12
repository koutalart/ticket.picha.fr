<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sms_unattributed_messages', function (Blueprint $table) {
            $table->unsignedBigInteger('chatwoot_conversation_id')->nullable()->index();
            $table->unsignedBigInteger('chatwoot_message_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sms_unattributed_messages', function (Blueprint $table) {
            $table->dropColumn(['chatwoot_conversation_id', 'chatwoot_message_id']);
        });
    }
};
