<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_feedback', static function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('contact_code', 64)->nullable();
            $table->unsignedTinyInteger('rating');
            $table->string('title', 80)->nullable();
            $table->string('first_name', 40)->nullable();
            $table->string('artists', 80)->nullable();
            $table->string('welcome', 80)->nullable();
            $table->string('come_back', 80)->nullable();
            $table->text('message')->nullable();
            $table->boolean('publish_consent')->default(false);
            $table->string('ip_hash', 64)->nullable();
            $table->timestamps();

            $table->unique(['event_id', 'contact_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_feedback');
    }
};
