<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizer_settings', function (Blueprint $table) {
            $table->boolean('sms_default_reminders_enabled')->default(false);
        });

        Schema::table('sms_campaigns', function (Blueprint $table) {
            $table->string('auto_key', 30)->nullable();
            $table->boolean('auto_edited')->default(false);

            $table->unique(['event_id', 'auto_key']);
        });
    }

    public function down(): void
    {
        Schema::table('sms_campaigns', function (Blueprint $table) {
            $table->dropUnique(['event_id', 'auto_key']);
            $table->dropColumn(['auto_key', 'auto_edited']);
        });

        Schema::table('organizer_settings', function (Blueprint $table) {
            $table->dropColumn('sms_default_reminders_enabled');
        });
    }
};
