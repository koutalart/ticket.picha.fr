<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizer_sms_settings', function (Blueprint $table) {
            $table->boolean('checkin_welcome_sms_enabled')->default(false);
            $table->text('checkin_welcome_sms_prompt')->nullable();
            $table->text('checkin_welcome_sms_templates')->nullable();
        });

        Schema::create('sms_checkin_welcomes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organizer_id')->constrained('organizers')->cascadeOnDelete();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignId('attendee_id')->constrained('attendees')->cascadeOnDelete();
            $table->string('phone', 32);
            $table->string('status', 20);
            $table->string('source', 20)->nullable();
            $table->unsignedBigInteger('sms_message_id')->nullable();
            $table->string('failure_reason', 500)->nullable();
            $table->timestamps();

            $table->unique(['event_id', 'phone'], 'sms_checkin_welcomes_one_per_phone');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_checkin_welcomes');

        Schema::table('organizer_sms_settings', function (Blueprint $table) {
            $table->dropColumn(['checkin_welcome_sms_enabled', 'checkin_welcome_sms_prompt', 'checkin_welcome_sms_templates']);
        });
    }
};
