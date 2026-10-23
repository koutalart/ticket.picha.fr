<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('event_settings', function (Blueprint $table) {
            $table->boolean('rich_footer_enabled')->default(false);
            $table->jsonb('homepage_footer_settings')->nullable();
            $table->string('schema_event_type', 32)->default('Event');
        });
    }

    public function down(): void
    {
        Schema::table('event_settings', function (Blueprint $table) {
            $table->dropColumn(['rich_footer_enabled', 'homepage_footer_settings', 'schema_event_type']);
        });
    }
};
