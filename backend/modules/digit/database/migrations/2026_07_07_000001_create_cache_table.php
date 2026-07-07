<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Standard Laravel "database" cache store tables.
 *
 * Second (and deeper) layer of the same Module 3 root cause as the
 * jobs table migration: CACHE_STORE=redis meant that spatie/laravel-data's
 * DataStructureCache - which is consulted every time a Data object like
 * CreateAttendeeCheckInPublicDTO is hydrated via ::from() - touched Redis
 * on essentially every request, including the core check-in write path.
 * With QUEUE_CONNECTION alone switched to database, scans still failed
 * with a RedisException during ::from() the moment Redis was unreachable,
 * before the check-in logic ever ran.
 *
 * No app/ code uses Cache::tags() (verified before making this change),
 * so moving the default cache store off Redis has no feature-parity cost
 * here. This is a schema addition only - no core Hi.Events file is
 * modified.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('cache')) {
            Schema::create('cache', function (Blueprint $table) {
                $table->string('key')->primary();
                $table->mediumText('value');
                $table->integer('expiration');
            });
        }

        if (!Schema::hasTable('cache_locks')) {
            Schema::create('cache_locks', function (Blueprint $table) {
                $table->string('key')->primary();
                $table->string('owner');
                $table->integer('expiration');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cache_locks');
        Schema::dropIfExists('cache');
    }
};
