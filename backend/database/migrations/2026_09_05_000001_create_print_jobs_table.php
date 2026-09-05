<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D14 (PICHA_BOX_OFFICE_DECISIONS_REQUIRED.md): every reprint of a ticket
 * (never a new Order/Attendee, always the same one) is traced here for
 * audit.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('print_jobs', static function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_user_id')->constrained('users');
            $table->string('reason')->nullable();
            $table->timestamp('printed_at');
            $table->timestamps();

            $table->index('attendee_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('print_jobs');
    }
};
