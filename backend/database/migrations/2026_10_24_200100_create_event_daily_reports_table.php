<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_daily_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->unique()->constrained('events')->cascadeOnDelete();
            $table->boolean('enabled')->default(false);
            $table->string('send_time', 5)->default('20:00');
            $table->string('timezone', 64)->default('Indian/Mayotte');
            $table->boolean('copy_supervisors')->default(false);
            $table->date('last_sent_on')->nullable();
            $table->timestamps();

            $table->index('enabled');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_daily_reports');
    }
};
