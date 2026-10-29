<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizer_sms_settings', function (Blueprint $table) {
            $table->string('live_chat_style', 20)->default('CLASSIC');
        });
    }

    public function down(): void
    {
        Schema::table('organizer_sms_settings', function (Blueprint $table) {
            $table->dropColumn('live_chat_style');
        });
    }
};
