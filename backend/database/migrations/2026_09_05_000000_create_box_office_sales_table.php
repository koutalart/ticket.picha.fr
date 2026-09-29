<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D3 / D9 (PICHA_BOX_OFFICE_DECISIONS_REQUIRED.md): projection table for
 * Box Office sales — traces payment method, the agent who sold the ticket,
 * and the idempotency key that makes a client-side retry safe (S3,
 * PICHA_BOX_OFFICE_SECURITY_FINDINGS.md).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('box_office_sales', static function (Blueprint $table) {
            $table->id();
            $table->string('idempotency_key')->unique();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_user_id')->constrained('users');
            $table->foreignId('product_id')->nullable()->constrained();
            $table->foreignId('product_price_id')->nullable()->constrained();
            $table->foreignId('order_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->foreignId('attendee_id')->nullable()->constrained()->nullOnDelete();
            $table->string('payment_method')->nullable();
            $table->decimal('amount', 14, 2)->nullable();
            $table->decimal('amount_collected', 14, 2)->nullable();
            $table->string('status')->default('PENDING');
            $table->timestamps();

            $table->index('event_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('box_office_sales');
    }
};
