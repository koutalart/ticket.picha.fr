<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizer_sms_settings', function (Blueprint $table) {
            $table->foreignId('shared_line_settings_id')
                ->nullable()
                ->constrained('organizer_sms_settings')
                ->nullOnDelete();
            $table->index('shared_line_settings_id');
        });

        Schema::create('sms_unattributed_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('line_settings_id')->constrained('organizer_sms_settings')->cascadeOnDelete();
            $table->string('phone', 20);
            $table->text('content');
            $table->string('provider_message_id', 64)->unique();
            $table->timestamp('provider_timestamp')->nullable();
            $table->boolean('opt_out_applied')->default(false);
            $table->foreignId('assigned_organizer_id')->nullable()->constrained('organizers')->nullOnDelete();
            $table->foreignId('sms_message_id')->nullable()->constrained('sms_messages')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamps();

            $table->index(['line_settings_id', 'assigned_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_unattributed_messages');

        Schema::table('organizer_sms_settings', function (Blueprint $table) {
            $table->dropForeign(['shared_line_settings_id']);
            $table->dropIndex(['shared_line_settings_id']);
            $table->dropColumn('shared_line_settings_id');
        });
    }
};
