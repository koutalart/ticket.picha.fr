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

        Schema::create('sms_unattributed_outbound_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('line_settings_id')->constrained('organizer_sms_settings')->cascadeOnDelete();
            $table->string('phone', 20);
            $table->text('content');
            $table->string('provider_message_id', 64)->unique();
            $table->string('request_id', 100)->nullable();
            $table->string('status', 20);
            $table->text('error_message')->nullable();
            $table->unsignedBigInteger('chatwoot_conversation_id')->nullable()->index();
            $table->unsignedBigInteger('chatwoot_message_id')->nullable();
            $table->timestamps();

            $table->index('phone');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_unattributed_outbound_messages');

        Schema::table('sms_unattributed_messages', function (Blueprint $table) {
            $table->dropColumn(['chatwoot_conversation_id', 'chatwoot_message_id']);
        });
    }
};
