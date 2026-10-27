<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_threads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('organizer_id')->constrained('organizers')->cascadeOnDelete();
            $table->string('email', 255);
            $table->string('contact_name', 150)->nullable();
            $table->string('status', 20)->default('OPEN');
            $table->unsignedInteger('unread_count')->default(0);
            $table->timestamp('last_message_at')->nullable();
            $table->string('last_message_subject', 255)->nullable();
            $table->string('last_message_preview', 200)->nullable();
            $table->string('last_message_direction', 10)->nullable();
            $table->string('last_message_status', 20)->nullable();
            $table->timestamps();

            $table->unique(['organizer_id', 'email']);
            $table->index(['organizer_id', 'last_message_at']);
        });

        Schema::create('email_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_thread_id')->constrained('email_threads')->cascadeOnDelete();
            $table->foreignId('organizer_id')->constrained('organizers')->cascadeOnDelete();
            $table->foreignId('event_id')->nullable()->constrained('events')->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignId('attendee_id')->nullable()->constrained('attendees')->nullOnDelete();
            $table->foreignId('outgoing_message_id')->nullable()->unique()->constrained('outgoing_messages')->nullOnDelete();
            $table->string('direction', 10);
            $table->string('source', 60);
            $table->foreignId('sent_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('from_email', 255)->nullable();
            $table->string('from_name', 150)->nullable();
            $table->string('to_email', 255);
            $table->string('subject', 255)->nullable();
            $table->string('preview', 500)->nullable();
            $table->text('body_text')->nullable();
            $table->text('body_html')->nullable();
            $table->jsonb('attachments')->nullable();
            $table->string('status', 20);
            $table->string('provider', 20)->nullable();
            $table->string('provider_message_id', 100)->nullable()->unique();
            $table->string('rfc_message_id', 255)->nullable()->index();
            $table->string('in_reply_to', 255)->nullable();
            $table->string('error_message', 500)->nullable();
            $table->timestamp('status_updated_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamps();

            $table->index(['email_thread_id', 'id']);
            $table->index(['organizer_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_messages');
        Schema::dropIfExists('email_threads');
    }
};
