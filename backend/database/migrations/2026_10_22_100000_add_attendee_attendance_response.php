<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('event_settings', function (Blueprint $table) {
            $table->boolean('allow_attendee_attendance_response')->default(false);
        });

        Schema::table('attendees', function (Blueprint $table) {
            $table->timestamp('attendance_confirmed_at')->nullable();
            $table->timestamp('cancelled_by_attendee_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('event_settings', function (Blueprint $table) {
            $table->dropColumn('allow_attendee_attendance_response');
        });

        Schema::table('attendees', function (Blueprint $table) {
            $table->dropColumn(['attendance_confirmed_at', 'cancelled_by_attendee_at']);
        });
    }
};
