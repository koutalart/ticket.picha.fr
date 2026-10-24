<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizer_calendars', function (Blueprint $table) {
            $table->string('access_mode', 16)->default('name');
        });

        DB::table('organizer_calendars')->update(['access_mode' => 'name']);
    }

    public function down(): void
    {
        Schema::table('organizer_calendars', function (Blueprint $table) {
            $table->dropColumn('access_mode');
        });
    }
};
