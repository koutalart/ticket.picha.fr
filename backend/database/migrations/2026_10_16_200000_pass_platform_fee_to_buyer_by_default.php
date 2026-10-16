<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizer_settings', function (Blueprint $table) {
            $table->boolean('default_pass_platform_fee_to_buyer')->default(true)->change();
        });

        Schema::table('event_settings', function (Blueprint $table) {
            $table->boolean('pass_platform_fee_to_buyer')->default(true)->change();
        });

        DB::table('organizer_settings')->where('default_pass_platform_fee_to_buyer', false)->update(['default_pass_platform_fee_to_buyer' => true]);
        DB::table('event_settings')->where('pass_platform_fee_to_buyer', false)->update(['pass_platform_fee_to_buyer' => true]);
    }

    public function down(): void
    {
        Schema::table('organizer_settings', function (Blueprint $table) {
            $table->boolean('default_pass_platform_fee_to_buyer')->default(false)->change();
        });

        Schema::table('event_settings', function (Blueprint $table) {
            $table->boolean('pass_platform_fee_to_buyer')->default(false)->change();
        });
    }
};
