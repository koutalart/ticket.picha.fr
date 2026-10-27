<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_daily_reports', function (Blueprint $table) {
            $table->boolean('activity_enabled')->default(false);
        });

        Schema::table('sms_messages', function (Blueprint $table) {
            $table->boolean('awaits_reply')->default(false);
        });

        Schema::table('organizer_sms_settings', function (Blueprint $table) {
            $table->boolean('organizer_nudge_enabled')->default(false);
            $table->foreignId('organizer_nudge_event_id')->nullable()->constrained('events')->nullOnDelete();
        });

        Schema::create('organizer_team_nudges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organizer_id')->constrained('organizers')->cascadeOnDelete();
            $table->foreignId('organizer_team_contact_id')->constrained('organizer_team_contacts')->cascadeOnDelete();
            $table->foreignId('awaited_sms_message_id')->constrained('sms_messages')->cascadeOnDelete();
            $table->foreignId('sms_message_id')->nullable()->constrained('sms_messages')->nullOnDelete();
            $table->string('phone', 20);
            $table->unsignedSmallInteger('variant');
            $table->string('status', 20);
            $table->timestamps();

            $table->unique(['organizer_team_contact_id', 'awaited_sms_message_id']);
        });

        // The messages of Anicka already sent to the people who run an organizer and that wait for an answer:
        // the introduction (an AGENT message that answers nothing) and the assistant answers that ask a question.
        DB::statement(<<<'SQL'
            UPDATE sms_messages SET awaits_reply = true
            FROM sms_conversations, organizer_team_contacts
            WHERE sms_messages.sms_conversation_id = sms_conversations.id
              AND organizer_team_contacts.organizer_id = sms_conversations.organizer_id
              AND organizer_team_contacts.phone = sms_conversations.phone
              AND organizer_team_contacts.role = 'ORGANIZER'
              AND sms_messages.direction = 'OUTBOUND'
              AND sms_messages.author_type = 'AGENT'
              AND sms_messages.is_supervision = false
              AND (
                sms_messages.auto_reply_to_message_id IS NULL
                OR (sms_messages.auto_reply_type = 'ANSWER' AND sms_messages.content LIKE '%?%')
              )
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('organizer_team_nudges');

        Schema::table('organizer_sms_settings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('organizer_nudge_event_id');
            $table->dropColumn('organizer_nudge_enabled');
        });

        Schema::table('sms_messages', function (Blueprint $table) {
            $table->dropColumn('awaits_reply');
        });

        Schema::table('event_daily_reports', function (Blueprint $table) {
            $table->dropColumn('activity_enabled');
        });
    }
};
