<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sms_messages', function (Blueprint $table) {
            $table->unsignedBigInteger('queue_message_id')->nullable()->index();
            $table->timestamp('queue_checked_at')->nullable();
        });

        Schema::table('sms_campaign_recipients', function (Blueprint $table) {
            $table->unsignedBigInteger('queue_message_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('sms_campaign_recipients', function (Blueprint $table) {
            $table->dropColumn('queue_message_id');
        });

        Schema::table('sms_messages', function (Blueprint $table) {
            $table->dropColumn(['queue_message_id', 'queue_checked_at']);
        });
    }
};
