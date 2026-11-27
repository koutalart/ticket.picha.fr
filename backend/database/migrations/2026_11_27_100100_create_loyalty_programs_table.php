<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_programs', static function (Blueprint $table) {
            $table->id();
            $table->foreignId('organizer_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('reward_description', 120);
            $table->unsignedSmallInteger('stamps_required')->default(9);
            $table->string('background_color', 7)->default('#1F2937');
            $table->string('foreground_color', 7)->default('#FFFFFF');
            $table->string('label_color', 7)->default('#D1D5DB');
            $table->string('logo_url', 500)->nullable();
            $table->jsonb('locations')->nullable();
            $table->unsignedSmallInteger('max_distance')->nullable();
            $table->uuid('beacon_uuid')->nullable();
            $table->unsignedInteger('beacon_major')->nullable();
            $table->unsignedInteger('beacon_minor')->nullable();
            $table->string('beacon_relevant_text', 120)->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_programs');
    }
};
