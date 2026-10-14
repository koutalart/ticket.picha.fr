<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qr_campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('organizer_id')->constrained('organizers')->cascadeOnDelete();
            $table->string('name', 80);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organizer_id', 'deleted_at']);
        });

        Schema::table('qr_links', function (Blueprint $table) {
            $table->foreignId('qr_campaign_id')->nullable()->after('event_id')->constrained('qr_campaigns')->nullOnDelete();
            $table->index('qr_campaign_id');
        });
    }

    public function down(): void
    {
        Schema::table('qr_links', function (Blueprint $table) {
            $table->dropForeign(['qr_campaign_id']);
            $table->dropColumn('qr_campaign_id');
        });

        Schema::dropIfExists('qr_campaigns');
    }
};
