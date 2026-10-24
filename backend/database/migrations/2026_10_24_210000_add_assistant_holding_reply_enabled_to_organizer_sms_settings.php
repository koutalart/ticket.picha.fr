<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizer_sms_settings', function (Blueprint $table) {
            $table->boolean('assistant_holding_reply_enabled')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('organizer_sms_settings', function (Blueprint $table) {
            $table->dropColumn('assistant_holding_reply_enabled');
        });
    }
};
