<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PICHA: amounts an organizer owes to the platform (e.g. cancellation protections paid out before 04/11),
 * withheld on the organizer part of their next Stripe Connect payments.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizer_platform_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organizer_id')->constrained('organizers')->cascadeOnDelete();
            $table->string('currency', 3)->default('EUR');
            $table->decimal('initial_amount', 14, 2);
            $table->decimal('remaining_amount', 14, 2);
            $table->string('reason', 500);
            $table->string('invoice_reference', 100)->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();

            $table->index(['organizer_id', 'currency']);
        });

        Schema::create('organizer_platform_balance_deductions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organizer_platform_balance_id')->constrained('organizer_platform_balances')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->unsignedBigInteger('stripe_payment_id')->nullable();
            $table->decimal('amount', 14, 2);
            $table->timestamps();

            $table->unique(['organizer_platform_balance_id', 'order_id'], 'organizer_platform_balance_deductions_one_per_order');
            $table->index('order_id');
        });

        Schema::table('stripe_payments', function (Blueprint $table) {
            $table->integer('platform_balance_withheld')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('stripe_payments', function (Blueprint $table) {
            $table->dropColumn('platform_balance_withheld');
        });

        Schema::dropIfExists('organizer_platform_balance_deductions');
        Schema::dropIfExists('organizer_platform_balances');
    }
};
