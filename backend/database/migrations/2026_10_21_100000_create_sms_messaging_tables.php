<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizer_sms_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('organizer_id')->unique()->constrained('organizers')->cascadeOnDelete();
            $table->string('provider', 20)->default('HTTPSMS');
            $table->text('api_key')->nullable();
            $table->string('from_number', 20)->nullable();
            $table->string('webhook_token', 64)->unique();
            $table->text('webhook_signing_key');
            $table->timestamp('last_webhook_received_at')->nullable();
            $table->timestamps();
        });

        Schema::create('sms_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('organizer_id')->constrained('organizers')->cascadeOnDelete();
            $table->string('phone', 20);
            $table->string('contact_name', 150)->nullable();
            $table->string('status', 20)->default('OPEN');
            $table->unsignedInteger('unread_count')->default(0);
            $table->timestamp('last_message_at')->nullable();
            $table->string('last_message_preview', 160)->nullable();
            $table->string('last_message_direction', 10)->nullable();
            $table->text('draft_reply')->nullable();
            $table->string('draft_source', 20)->nullable();
            $table->timestamp('draft_generated_at')->nullable();
            $table->timestamps();

            $table->unique(['organizer_id', 'phone']);
            $table->index(['organizer_id', 'last_message_at']);
        });

        Schema::create('sms_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sms_conversation_id')->constrained('sms_conversations')->cascadeOnDelete();
            $table->foreignId('organizer_id')->constrained('organizers')->cascadeOnDelete();
            $table->string('direction', 10);
            $table->string('author_type', 10);
            $table->foreignId('sent_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('content');
            $table->string('status', 20);
            $table->string('provider_message_id', 64)->nullable()->unique();
            $table->string('request_id', 64)->nullable()->index();
            $table->string('error_message', 500)->nullable();
            $table->timestamp('provider_timestamp')->nullable();
            $table->timestamps();

            $table->index(['sms_conversation_id', 'id']);
        });

        Schema::create('sms_opt_outs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organizer_id')->constrained('organizers')->cascadeOnDelete();
            $table->string('phone', 20);
            $table->string('source', 20);
            $table->timestamps();

            $table->unique(['organizer_id', 'phone']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_opt_outs');
        Schema::dropIfExists('sms_messages');
        Schema::dropIfExists('sms_conversations');
        Schema::dropIfExists('organizer_sms_settings');
    }
};
