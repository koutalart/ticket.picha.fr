<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_line_former_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('line_settings_id')->constrained('organizer_sms_settings')->cascadeOnDelete();
            $table->foreignId('organizer_id')->constrained('organizers')->cascadeOnDelete();
            $table->timestamp('detached_at');
            $table->timestamps();

            $table->unique(['line_settings_id', 'organizer_id']);
            $table->index('detached_at');
        });

        Schema::table('sms_messages', function (Blueprint $table) {
            $table->foreignId('line_settings_id')->nullable()->constrained('organizer_sms_settings')->nullOnDelete();
            $table->index('line_settings_id');
        });

        Schema::table('sms_conversations', function (Blueprint $table) {
            $table->foreignId('line_settings_id')->nullable()->constrained('organizer_sms_settings')->nullOnDelete();
        });

        $lineOfOrganizer = "
            SELECT s.organizer_id, COALESCE(s.shared_line_settings_id, s.id) AS line_settings_id
            FROM organizer_sms_settings s
            WHERE s.shared_line_settings_id IS NOT NULL
               OR (s.api_key IS NOT NULL AND s.from_number IS NOT NULL AND s.from_number <> '')
        ";

        DB::statement("
            UPDATE sms_messages m
            SET line_settings_id = l.line_settings_id
            FROM ({$lineOfOrganizer}) l
            WHERE l.organizer_id = m.organizer_id
              AND m.line_settings_id IS NULL
        ");

        DB::statement("
            UPDATE sms_conversations c
            SET line_settings_id = l.line_settings_id
            FROM ({$lineOfOrganizer}) l
            WHERE l.organizer_id = c.organizer_id
              AND c.line_settings_id IS NULL
        ");
    }

    public function down(): void
    {
        Schema::table('sms_conversations', function (Blueprint $table) {
            $table->dropForeign(['line_settings_id']);
            $table->dropColumn('line_settings_id');
        });

        Schema::table('sms_messages', function (Blueprint $table) {
            $table->dropForeign(['line_settings_id']);
            $table->dropIndex(['line_settings_id']);
            $table->dropColumn('line_settings_id');
        });

        Schema::dropIfExists('sms_line_former_members');
    }
};
