<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('organizer_calendars', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organizer_id')->constrained('organizers')->cascadeOnDelete();
            $table->string('slug', 100)->unique();
            $table->string('title');
            $table->string('phone', 32);
            $table->jsonb('content');
            $table->longText('front_html')->nullable();
            $table->timestamps();
        });

        Schema::create('organizer_calendar_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organizer_calendar_id')->constrained('organizer_calendars')->cascadeOnDelete();
            $table->string('file_name', 255);
            $table->string('disk', 50);
            $table->string('path', 500);
            $table->string('original_name', 255);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size');
            $table->timestamps();

            $table->index('organizer_calendar_id');
        });

        Schema::create('organizer_calendar_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organizer_calendar_id')->constrained('organizer_calendars')->cascadeOnDelete();
            $table->string('code_hash', 64);
            $table->timestamp('expires_at');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            $table->index(['organizer_calendar_id', 'created_at']);
        });

        Schema::create('organizer_calendar_accesses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organizer_calendar_id')->constrained('organizer_calendars')->cascadeOnDelete();
            $table->string('key_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['organizer_calendar_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizer_calendar_accesses');
        Schema::dropIfExists('organizer_calendar_codes');
        Schema::dropIfExists('organizer_calendar_media');
        Schema::dropIfExists('organizer_calendars');
    }
};
