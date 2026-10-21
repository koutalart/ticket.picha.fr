<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizer_sms_settings', function (Blueprint $table) {
            $table->string('history_import_status', 20)->nullable();
            $table->timestamp('history_import_started_at')->nullable();
            $table->timestamp('history_import_finished_at')->nullable();
            $table->unsignedInteger('history_import_count')->nullable();
            $table->string('history_import_error', 500)->nullable();
        });

        Schema::create('sms_reply_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organizer_id')->constrained('organizers')->cascadeOnDelete();
            $table->string('name', 100);
            $table->text('content');
            $table->timestamps();

            $table->index(['organizer_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_reply_templates');

        Schema::table('organizer_sms_settings', function (Blueprint $table) {
            $table->dropColumn([
                'history_import_status',
                'history_import_started_at',
                'history_import_finished_at',
                'history_import_count',
                'history_import_error',
            ]);
        });
    }
};
