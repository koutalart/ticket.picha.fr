<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizer_fonts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('organizer_id')->constrained('organizers')->cascadeOnDelete();
            $table->string('family_name', 100);
            $table->unsignedSmallInteger('weight');
            $table->string('format', 20);
            $table->string('filename', 255);
            $table->string('disk', 50);
            $table->string('path', 500);
            $table->unsignedInteger('size');
            $table->timestamps();

            $table->unique(['organizer_id', 'weight']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizer_fonts');
    }
};
