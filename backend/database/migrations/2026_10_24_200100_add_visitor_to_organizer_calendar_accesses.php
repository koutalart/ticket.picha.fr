<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizer_calendar_accesses', function (Blueprint $table) {
            $table->string('visitor_name', 60)->nullable();
            $table->string('device_id', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('organizer_calendar_accesses', function (Blueprint $table) {
            $table->dropColumn(['visitor_name', 'device_id']);
        });
    }
};
