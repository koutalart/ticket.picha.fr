<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobile_passes', static function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('pass_serial')->unique();
            $table->string('type');
            $table->string('platform');
            $table->string('builder_name');
            $table->json('content');
            $table->json('images');
            $table->json('locales')->nullable();
            $table->string('download_name')->nullable();
            $table->nullableMorphs('model');
            $table->timestamp('expired_at')->nullable();
            $table->timestamps();
        });

        Schema::create('apple_mobile_pass_devices', static function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('push_token');
            $table->timestamps();
        });

        Schema::create('apple_mobile_pass_registrations', static function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('device_id');
            $table->foreign('device_id')->references('id')->on('apple_mobile_pass_devices');
            $table->string('pass_type_id');
            $table->foreignUuid('mobile_pass_id')->constrained('mobile_passes', 'id');
            $table->timestamps();

            $table->index(['device_id', 'mobile_pass_id']);
            $table->index(['device_id', 'pass_type_id']);
        });

        Schema::create('mobile_pass_google_events', static function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('mobile_pass_id')->constrained('mobile_passes')->cascadeOnDelete();
            $table->string('event_type');
            $table->timestamp('received_at');
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->index(['mobile_pass_id', 'event_type']);
            $table->index('received_at');
        });

        Schema::create('apple_mobile_pass_personalizations', static function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('mobile_pass_id')->unique()->constrained('mobile_passes')->cascadeOnDelete();
            $table->string('description');
            $table->json('required_fields');
            $table->text('terms_and_conditions')->nullable();
            $table->string('personalization_token')->nullable();
            $table->json('submitted_info')->nullable();
            $table->timestamp('personalized_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('apple_mobile_pass_personalizations');
        Schema::dropIfExists('mobile_pass_google_events');
        Schema::dropIfExists('apple_mobile_pass_registrations');
        Schema::dropIfExists('apple_mobile_pass_devices');
        Schema::dropIfExists('mobile_passes');
    }
};
