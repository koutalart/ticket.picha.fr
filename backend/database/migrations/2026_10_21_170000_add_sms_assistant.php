<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizer_sms_settings', function (Blueprint $table) {
            $table->boolean('assistant_enabled')->default(false);
            $table->text('assistant_instructions')->nullable();
        });

        Schema::table('sms_conversations', function (Blueprint $table) {
            $table->string('needs_human_reason', 255)->nullable();
        });

        Schema::create('sms_assistant_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organizer_id')->constrained('organizers')->cascadeOnDelete();
            $table->foreignId('sms_conversation_id')->nullable()->constrained('sms_conversations')->nullOnDelete();
            $table->string('trigger', 20);
            $table->string('outcome', 20);
            $table->string('model', 64)->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();

            $table->index(['organizer_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_assistant_calls');

        Schema::table('sms_conversations', function (Blueprint $table) {
            $table->dropColumn('needs_human_reason');
        });

        Schema::table('organizer_sms_settings', function (Blueprint $table) {
            $table->dropColumn(['assistant_enabled', 'assistant_instructions']);
        });
    }
};
