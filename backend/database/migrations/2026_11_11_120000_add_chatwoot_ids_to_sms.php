<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sms_conversations', function (Blueprint $table) {
            $table->unsignedBigInteger('chatwoot_conversation_id')->nullable()->index();
        });

        Schema::table('sms_messages', function (Blueprint $table) {
            $table->unsignedBigInteger('chatwoot_message_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('sms_messages', function (Blueprint $table) {
            $table->dropColumn('chatwoot_message_id');
        });

        Schema::table('sms_conversations', function (Blueprint $table) {
            $table->dropColumn('chatwoot_conversation_id');
        });
    }
};
