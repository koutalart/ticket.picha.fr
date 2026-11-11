<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_actions', function (Blueprint $table) {
            $table->id();
            $table->string('command', 60);
            $table->unsignedSmallInteger('level');
            $table->string('status', 30);
            $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->foreignId('organizer_id')->nullable()->constrained('organizers')->nullOnDelete();
            $table->foreignId('event_id')->nullable()->constrained('events')->nullOnDelete();
            $table->string('requested_by', 150);
            $table->jsonb('params')->nullable();
            $table->string('params_hash', 64)->nullable();
            $table->text('summary')->nullable();
            $table->jsonb('result')->nullable();
            $table->text('error')->nullable();
            $table->string('confirmation_token_hash', 64)->nullable()->unique();
            $table->timestamp('confirmation_expires_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamps();

            $table->index(['organizer_id', 'id']);
            $table->index(['status', 'confirmation_expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_actions');
    }
};
