<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('qr_links', function (Blueprint $table) {
            $table->string('app_store_url', 2048)->nullable()->after('destination_url');
            $table->string('play_store_url', 2048)->nullable()->after('app_store_url');
        });
    }

    public function down(): void
    {
        Schema::table('qr_links', function (Blueprint $table) {
            $table->dropColumn(['app_store_url', 'play_store_url']);
        });
    }
};
