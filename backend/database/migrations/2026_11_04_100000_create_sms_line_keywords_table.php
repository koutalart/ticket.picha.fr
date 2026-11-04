<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_line_keywords', function (Blueprint $table) {
            $table->id();
            $table->foreignId('line_settings_id')->constrained('organizer_sms_settings')->cascadeOnDelete();
            $table->foreignId('organizer_id')->constrained('organizers')->cascadeOnDelete();
            $table->string('keyword', 30);
            $table->boolean('is_active')->default(true);
            $table->text('welcome_message')->nullable();
            $table->timestamps();

            $table->unique(['line_settings_id', 'keyword']);
            $table->index('organizer_id');
        });

        Schema::table('sms_messages', function (Blueprint $table) {
            $table->string('line_keyword', 30)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sms_messages', function (Blueprint $table) {
            $table->dropColumn('line_keyword');
        });

        Schema::dropIfExists('sms_line_keywords');
    }
};
