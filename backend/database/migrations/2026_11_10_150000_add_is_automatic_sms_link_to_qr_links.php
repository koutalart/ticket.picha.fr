<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('qr_links', function (Blueprint $table) {
            $table->boolean('is_automatic_sms_link')->default(false);
        });

        DB::statement('CREATE INDEX qr_links_automatic_sms_destination ON qr_links USING hash (destination_url) WHERE is_automatic_sms_link');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS qr_links_automatic_sms_destination');

        Schema::table('qr_links', function (Blueprint $table) {
            $table->dropColumn('is_automatic_sms_link');
        });
    }
};
