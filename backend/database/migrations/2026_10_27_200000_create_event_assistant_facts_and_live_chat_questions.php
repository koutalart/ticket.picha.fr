<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('live_chat_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organizer_id')->constrained('organizers')->cascadeOnDelete();
            $table->foreignId('event_id')->nullable()->constrained('events')->nullOnDelete();
            $table->foreignId('live_chat_session_id')->nullable()->constrained('live_chat_sessions')->nullOnDelete();
            $table->text('question');
            $table->text('visitor_message')->nullable();
            $table->string('status', 20)->default('AWAITING');
            $table->string('notified_phones', 255)->nullable();
            $table->boolean('sent_to_supervision')->default(false);
            $table->timestamp('last_notified_at')->nullable();
            $table->unsignedSmallInteger('reminders_count')->default(0);
            $table->timestamp('next_reminder_at')->nullable();
            $table->timestamp('escalated_at')->nullable();
            $table->text('answer')->nullable();
            $table->string('answered_by_phone', 20)->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->timestamp('relayed_at')->nullable();
            $table->timestamps();

            $table->index(['organizer_id', 'status']);
            $table->index(['status', 'next_reminder_at']);
            $table->index(['live_chat_session_id', 'status']);
        });

        Schema::create('event_assistant_facts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignId('organizer_id')->constrained('organizers')->cascadeOnDelete();
            $table->string('subject', 255)->nullable();
            $table->text('answer');
            $table->string('source', 20);
            $table->foreignId('live_chat_question_id')->nullable()->constrained('live_chat_questions')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->index(['event_id', 'confirmed_at']);
        });

        Schema::create('event_assistant_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->unique()->constrained('events')->cascadeOnDelete();
            $table->json('snapshot');
            $table->timestamps();
        });

        Schema::table('live_chat_sessions', function (Blueprint $table) {
            $table->timestamp('contact_asked_at')->nullable();
            $table->timestamp('contact_declined_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('live_chat_sessions', function (Blueprint $table) {
            $table->dropColumn(['contact_asked_at', 'contact_declined_at']);
        });

        Schema::dropIfExists('event_assistant_snapshots');
        Schema::dropIfExists('event_assistant_facts');
        Schema::dropIfExists('live_chat_questions');
    }
};
