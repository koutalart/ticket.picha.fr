<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('live_chat_ticket_resend_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('live_chat_session_id')->constrained('live_chat_sessions')->cascadeOnDelete();
            $table->foreignId('organizer_id')->constrained('organizers')->cascadeOnDelete();
            $table->string('phone_hash', 64);
            $table->jsonb('order_ids')->nullable();
            $table->string('status', 20);
            $table->string('code_hash', 255)->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('code_expires_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->string('ip_hash', 64)->nullable();
            $table->timestamps();

            $table->index(['live_chat_session_id', 'id']);
            $table->index(['phone_hash', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('live_chat_ticket_resend_requests');
    }
};
