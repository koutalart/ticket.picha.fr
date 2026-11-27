<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_cards', static function (Blueprint $table) {
            $table->id();
            $table->foreignId('loyalty_program_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organizer_id')->constrained()->cascadeOnDelete();
            $table->string('public_id', 40)->unique();
            $table->string('first_name', 40);
            $table->string('email', 255)->nullable();
            $table->string('phone', 25)->nullable();
            $table->unsignedSmallInteger('stamps')->default(0);
            $table->unsignedInteger('rewards_earned')->default(0);
            $table->unsignedInteger('total_stamps')->default(0);
            $table->timestamp('last_stamped_at')->nullable();
            $table->uuid('apple_mobile_pass_id')->nullable();
            $table->uuid('google_mobile_pass_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['loyalty_program_id', 'email']);
            $table->unique(['loyalty_program_id', 'phone']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_cards');
    }
};
