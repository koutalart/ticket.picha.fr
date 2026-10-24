<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('live_chat_refund_requests', function (Blueprint $table) {
            $table->string('customer_phone', 20)->nullable();
            $table->string('customer_email', 255)->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('awaiting_approval_at')->nullable();
            $table->string('decision', 20)->nullable();
            $table->string('decided_by_channel', 20)->nullable();
            $table->string('decided_by_phone', 20)->nullable();
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decided_by_name', 150)->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('approval_link_used_at')->nullable();
            $table->timestamp('next_reminder_at')->nullable();
            $table->unsignedInteger('reminders_count')->default(0);
            $table->timestamp('last_reminder_at')->nullable();
            $table->timestamp('next_customer_update_at')->nullable();
            $table->unsignedInteger('customer_updates_count')->default(0);
            $table->timestamp('last_customer_update_at')->nullable();
            $table->timestamp('escalated_at')->nullable();

            $table->index(['status', 'next_reminder_at']);
            $table->index(['status', 'next_customer_update_at']);
        });

        Schema::create('live_chat_refund_request_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('live_chat_refund_request_id')->constrained('live_chat_refund_requests')->cascadeOnDelete();
            $table->string('action', 40);
            $table->string('channel', 20)->nullable();
            $table->string('actor_phone', 20)->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name', 150)->nullable();
            $table->text('details')->nullable();
            $table->timestamps();

            $table->index(['live_chat_refund_request_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('live_chat_refund_request_logs');

        Schema::table('live_chat_refund_requests', function (Blueprint $table) {
            $table->dropIndex(['status', 'next_reminder_at']);
            $table->dropIndex(['status', 'next_customer_update_at']);
            $table->dropConstrainedForeignId('decided_by_user_id');
            $table->dropColumn([
                'customer_phone', 'customer_email', 'reason', 'awaiting_approval_at', 'decision', 'decided_by_channel',
                'decided_by_phone', 'decided_by_name', 'decided_at', 'approval_link_used_at', 'next_reminder_at',
                'reminders_count', 'last_reminder_at', 'next_customer_update_at', 'customer_updates_count',
                'last_customer_update_at', 'escalated_at',
            ]);
        });
    }
};
