<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PICHA: when an order with a withholding is refunded, the withheld amount goes back to the balance, once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizer_platform_balance_deductions', function (Blueprint $table) {
            $table->timestamp('restored_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('organizer_platform_balance_deductions', function (Blueprint $table) {
            $table->dropColumn('restored_at');
        });
    }
};
