<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PICHA (05/11): an amount owed is only withheld once the organizer validated it. Existing balances
 * stay suspended until they are validated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizer_platform_balances', function (Blueprint $table) {
            $table->timestamp('organizer_validated_at')->nullable()->after('invoice_reference');
            $table->string('organizer_validated_by', 255)->nullable()->after('organizer_validated_at');
        });
    }

    public function down(): void
    {
        Schema::table('organizer_platform_balances', function (Blueprint $table) {
            $table->dropColumn(['organizer_validated_at', 'organizer_validated_by']);
        });
    }
};
