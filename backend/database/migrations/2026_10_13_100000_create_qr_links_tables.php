<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qr_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('organizer_id')->constrained('organizers')->cascadeOnDelete();
            $table->foreignId('event_id')->nullable()->constrained('events')->nullOnDelete();
            $table->string('short_id', 32)->unique();
            $table->string('name', 120);
            $table->string('destination_type', 20);
            $table->string('destination_url', 2048)->nullable();
            $table->string('title', 150)->nullable();
            $table->string('description', 500)->nullable();
            $table->jsonb('social_links')->nullable();
            $table->jsonb('design')->nullable();
            $table->string('status', 20)->default('ACTIVE');
            $table->unsignedInteger('scans_count')->default(0);
            $table->unsignedInteger('unique_scans_count')->default(0);
            $table->unsignedInteger('clicks_count')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organizer_id', 'deleted_at']);
        });

        Schema::create('qr_link_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('qr_link_id')->constrained('qr_links')->cascadeOnDelete();
            $table->string('type', 10);
            $table->string('target', 30)->nullable();
            $table->string('visitor_hash', 64);
            $table->boolean('is_unique')->default(false);
            $table->string('device_type', 10)->nullable();
            $table->string('os', 20)->nullable();
            $table->string('browser', 20)->nullable();
            $table->char('country_code', 2)->nullable();
            $table->string('referrer_host', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['qr_link_id', 'created_at']);
            $table->index(['qr_link_id', 'visitor_hash']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('qr_link_id')->nullable()->constrained('qr_links')->nullOnDelete();
            $table->jsonb('tracking_context')->nullable();
            $table->timestamp('meta_capi_sent_at')->nullable();
            $table->index('qr_link_id');
        });

        Schema::table('organizer_settings', function (Blueprint $table) {
            $table->text('meta_conversions_api_token')->nullable();
            $table->string('meta_test_event_code', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('organizer_settings', function (Blueprint $table) {
            $table->dropColumn(['meta_conversions_api_token', 'meta_test_event_code']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['qr_link_id']);
            $table->dropColumn(['qr_link_id', 'tracking_context', 'meta_capi_sent_at']);
        });

        Schema::dropIfExists('qr_link_visits');
        Schema::dropIfExists('qr_links');
    }
};
