<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizer_team_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organizer_id')->constrained('organizers')->cascadeOnDelete();
            $table->string('phone', 20);
            $table->string('name', 150)->nullable();
            $table->string('role', 20);
            $table->timestamps();

            $table->unique(['organizer_id', 'phone']);
            $table->index('phone');
        });

        Schema::table('sms_conversations', function (Blueprint $table) {
            $table->boolean('is_team')->default(false);
        });

        Schema::table('sms_messages', function (Blueprint $table) {
            $table->boolean('is_supervision')->default(false);
        });

        Schema::table('organizer_sms_settings', function (Blueprint $table) {
            $table->boolean('organizer_assistant_enabled')->default(false);
            $table->text('organizer_assistant_instructions')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('organizer_sms_settings', function (Blueprint $table) {
            $table->dropColumn(['organizer_assistant_enabled', 'organizer_assistant_instructions']);
        });

        Schema::table('sms_messages', function (Blueprint $table) {
            $table->dropColumn('is_supervision');
        });

        Schema::table('sms_conversations', function (Blueprint $table) {
            $table->dropColumn('is_team');
        });

        Schema::dropIfExists('organizer_team_contacts');
    }
};
