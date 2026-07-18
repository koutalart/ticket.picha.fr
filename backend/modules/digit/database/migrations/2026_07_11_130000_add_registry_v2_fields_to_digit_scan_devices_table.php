<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('digit_scan_devices', function (Blueprint $table) {
            $table->string('display_id')->nullable()->unique()->after('id');
            $table->string('device_model')->nullable()->after('name');
            $table->boolean('all_check_in_lists')->default(false)->after('check_in_list_id');
            $table->string('manifest_version')->nullable()->after('last_synced_at');
            $table->timestamp('manifest_generated_at')->nullable()->after('manifest_version');
            $table->integer('manifest_bracelet_count')->nullable()->after('manifest_generated_at');
            $table->integer('pending_scan_count')->default(0)->after('manifest_bracelet_count');
            $table->integer('sync_error_count')->default(0)->after('pending_scan_count');
            $table->string('cache_state')->default('NONE')->after('sync_error_count');
        });
    }

    public function down(): void
    {
        Schema::table('digit_scan_devices', function (Blueprint $table) {
            $table->dropColumn([
                'display_id', 'device_model', 'all_check_in_lists',
                'manifest_version', 'manifest_generated_at', 'manifest_bracelet_count',
                'pending_scan_count', 'sync_error_count', 'cache_state',
            ]);
        });
    }
};
