<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_settings', function (Blueprint $table) {
            $table->string('price_display_mode')->default('EXCLUSIVE')->change();
        });

        DB::table('event_settings')->where('price_display_mode', '!=', 'EXCLUSIVE')->update(['price_display_mode' => 'EXCLUSIVE']);
        DB::table('event_settings')->where('allow_attendee_self_edit', false)->update(['allow_attendee_self_edit' => true]);
        DB::table('organizer_settings')->where('default_allow_attendee_self_edit', false)->update(['default_allow_attendee_self_edit' => true]);
    }

    public function down(): void
    {
        Schema::table('event_settings', function (Blueprint $table) {
            $table->string('price_display_mode')->default('INCLUSIVE')->change();
        });
    }
};
