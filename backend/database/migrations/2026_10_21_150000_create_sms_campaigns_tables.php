<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('organizer_id')->constrained('organizers')->cascadeOnDelete();
            $table->foreignId('event_id')->nullable()->constrained('events')->nullOnDelete();
            $table->string('name', 120);
            $table->text('message')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organizer_id', 'deleted_at']);
        });

        Schema::create('sms_campaign_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sms_campaign_id')->constrained('sms_campaigns')->cascadeOnDelete();
            $table->string('phone', 20);
            $table->string('contact_name', 150)->nullable();
            $table->foreignId('qr_link_id')->nullable()->unique()->constrained('qr_links')->nullOnDelete();
            $table->string('provider_message_id', 64)->nullable()->unique();
            $table->string('status', 20)->default('PENDING');
            $table->string('failure_reason', 500)->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->unique(['sms_campaign_id', 'phone']);
        });

        Schema::table('qr_links', function (Blueprint $table) {
            $table->foreignId('sms_campaign_id')->nullable()->after('qr_campaign_id')->constrained('sms_campaigns')->nullOnDelete();
            $table->index('sms_campaign_id');
        });
    }

    public function down(): void
    {
        Schema::table('qr_links', function (Blueprint $table) {
            $table->dropForeign(['sms_campaign_id']);
            $table->dropColumn('sms_campaign_id');
        });

        Schema::dropIfExists('sms_campaign_recipients');
        Schema::dropIfExists('sms_campaigns');
    }
};
