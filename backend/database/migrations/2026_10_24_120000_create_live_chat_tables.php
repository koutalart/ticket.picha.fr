<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizer_sms_settings', function (Blueprint $table) {
            $table->boolean('live_chat_enabled')->default(true);
            $table->string('live_chat_relay_label', 120)->nullable();
        });

        DB::table('organizer_sms_settings')->update(['live_chat_enabled' => true]);

        Schema::table('sms_messages', function (Blueprint $table) {
            $table->string('channel', 20)->nullable();
        });

        Schema::create('live_chat_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 64)->unique();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('organizer_id')->constrained('organizers')->cascadeOnDelete();
            $table->foreignId('event_id')->nullable()->constrained('events')->nullOnDelete();
            $table->foreignId('sms_conversation_id')->nullable()->constrained('sms_conversations')->nullOnDelete();
            $table->string('status', 20)->default('OPEN');
            $table->string('ip_hash', 64)->nullable();
            $table->string('visitor_phone', 20)->nullable();
            $table->string('visitor_email', 255)->nullable();
            $table->boolean('needs_follow_up')->default(false);
            $table->string('follow_up_reason', 255)->nullable();
            $table->unsignedInteger('visitor_message_count')->default(0);
            $table->unsignedInteger('model_call_count')->default(0);
            $table->timestamp('contact_given_at')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->index(['organizer_id', 'last_message_at']);
            $table->index(['ip_hash', 'created_at']);
        });

        Schema::create('live_chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('live_chat_session_id')->constrained('live_chat_sessions')->cascadeOnDelete();
            $table->string('author_type', 10);
            $table->text('content');
            $table->timestamps();

            $table->index(['live_chat_session_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('live_chat_messages');
        Schema::dropIfExists('live_chat_sessions');

        Schema::table('sms_messages', function (Blueprint $table) {
            $table->dropColumn('channel');
        });

        Schema::table('organizer_sms_settings', function (Blueprint $table) {
            $table->dropColumn(['live_chat_enabled', 'live_chat_relay_label']);
        });
    }
};
