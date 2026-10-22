<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('event_settings', function (Blueprint $table) {
            $table->boolean('sms_registration_confirmation_enabled')->default(false);
            $table->text('sms_registration_confirmation_message')->nullable();
        });

        Schema::create('sms_registration_confirmations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organizer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attendee_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('phone', 20);
            $table->string('status', 20);
            $table->foreignId('sms_message_id')->nullable()->constrained()->nullOnDelete();
            $table->string('failure_reason', 500)->nullable();
            $table->timestamps();

            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_registration_confirmations');

        Schema::table('event_settings', function (Blueprint $table) {
            $table->dropColumn(['sms_registration_confirmation_enabled', 'sms_registration_confirmation_message']);
        });
    }
};
