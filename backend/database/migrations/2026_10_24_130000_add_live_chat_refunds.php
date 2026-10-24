<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_refund_protection')->default(false);
        });

        Schema::table('event_settings', function (Blueprint $table) {
            $table->unsignedInteger('refund_protection_deadline_hours')->default(0);
        });

        Schema::table('live_chat_sessions', function (Blueprint $table) {
            $table->timestamp('refunded_at')->nullable();
        });

        Schema::create('live_chat_refund_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('live_chat_session_id')->nullable()->constrained('live_chat_sessions')->nullOnDelete();
            $table->foreignId('organizer_id')->constrained('organizers')->cascadeOnDelete();
            $table->foreignId('event_id')->nullable()->constrained('events')->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->jsonb('attendee_ids')->nullable();
            $table->decimal('amount', 14, 2)->nullable();
            $table->string('currency', 3)->nullable();
            $table->string('status', 20);
            $table->string('code_hash', 255)->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('code_expires_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->string('failure_reason', 255)->nullable();
            $table->string('ip_hash', 64)->nullable();
            $table->timestamps();

            $table->index(['order_id', 'created_at']);
            $table->index(['organizer_id', 'status', 'refunded_at']);
            $table->index(['live_chat_session_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('live_chat_refund_requests');

        Schema::table('live_chat_sessions', function (Blueprint $table) {
            $table->dropColumn('refunded_at');
        });

        Schema::table('event_settings', function (Blueprint $table) {
            $table->dropColumn('refund_protection_deadline_hours');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('is_refund_protection');
        });
    }
};
