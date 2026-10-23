<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sms_campaigns', function (Blueprint $table) {
            $table->string('type', 20)->default('IMPORTED');
            $table->string('status', 20)->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('failure_reason', 500)->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->index(['type', 'status', 'scheduled_at']);
        });

        Schema::table('sms_campaign_recipients', function (Blueprint $table) {
            $table->foreignId('attendee_id')->nullable()->constrained('attendees')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sms_campaign_recipients', function (Blueprint $table) {
            $table->dropForeign(['attendee_id']);
            $table->dropColumn('attendee_id');
        });

        Schema::table('sms_campaigns', function (Blueprint $table) {
            $table->dropIndex(['type', 'status', 'scheduled_at']);
            $table->dropForeign(['created_by_user_id']);
            $table->dropColumn(['type', 'status', 'scheduled_at', 'started_at', 'completed_at', 'failure_reason', 'created_by_user_id']);
        });
    }
};
