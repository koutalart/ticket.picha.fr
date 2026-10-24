<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizer_calendar_consultations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organizer_calendar_id')->constrained('organizer_calendars')->cascadeOnDelete();
            $table->foreignId('organizer_calendar_access_id')->nullable()->constrained('organizer_calendar_accesses')->nullOnDelete();
            $table->string('visitor_name', 60)->nullable();
            $table->string('device_id', 64)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('kind', 16);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['organizer_calendar_id', 'created_at']);
            $table->index(['organizer_calendar_access_id', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizer_calendar_consultations');
    }
};
