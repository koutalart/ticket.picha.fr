<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('digit_scan_devices', function (Blueprint $table) {
            $table->string('status', 20)->default('ACTIVE')->after('revoked_at');
            $table->timestamp('last_synced_at')->nullable()->after('last_used_at');
            $table->string('assigned_agent')->nullable()->after('check_in_list_id');
        });
    }

    public function down(): void
    {
        Schema::table('digit_scan_devices', function (Blueprint $table) {
            $table->dropColumn(['status', 'last_synced_at', 'assigned_agent']);
        });
    }
};
