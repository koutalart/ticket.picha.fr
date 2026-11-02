<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizer_sms_settings', function (Blueprint $table) {
            $table->boolean('organizer_assistant_replies_to_copy')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('organizer_sms_settings', function (Blueprint $table) {
            $table->dropColumn('organizer_assistant_replies_to_copy');
        });
    }
};
