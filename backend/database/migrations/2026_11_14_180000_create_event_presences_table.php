<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_presences', static function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('phone', 25);
            $table->string('first_name', 40);
            $table->string('last_name', 60)->nullable();
            $table->string('staff', 30)->nullable();
            $table->string('ip_hash', 64)->nullable();
            $table->timestamps();

            $table->unique(['event_id', 'phone']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_presences');
    }
};
