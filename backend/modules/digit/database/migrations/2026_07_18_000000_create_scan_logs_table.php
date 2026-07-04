<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scan_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('event_id');
            $table->unsignedBigInteger('check_in_list_id');
            $table->unsignedBigInteger('attendee_id');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('scanned_by_user_id')->nullable();
            $table->enum('direction', ['check_in', 'check_out'])->default('check_in');
            $table->enum('result', ['recorded', 'duplicate', 'rejected', 'duplicate_retry'])->default('recorded');
            $table->string('idempotency_key')->nullable();
            $table->string('device_identifier')->nullable();
            $table->timestamp('scanned_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('account_id')->references('id')->on('accounts')->cascadeOnDelete();
            $table->foreign('event_id')->references('id')->on('events')->cascadeOnDelete();
            $table->foreign('check_in_list_id')->references('id')->on('check_in_lists')->cascadeOnDelete();
            $table->foreign('attendee_id')->references('id')->on('attendees')->cascadeOnDelete();
            $table->foreign('product_id')->references('id')->on('products')->nullOnDelete();
            $table->foreign('scanned_by_user_id')->references('id')->on('users')->nullOnDelete();

            $table->index(['account_id', 'created_at']);
            $table->index(['event_id', 'attendee_id']);
            $table->index(['check_in_list_id', 'result', 'created_at']);
            $table->unique('idempotency_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scan_logs');
    }
};
