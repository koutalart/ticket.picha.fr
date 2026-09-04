<?php

declare(strict_types=1);

namespace Digit\Tests\Scan\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Base for Scan/Devices Feature tests. Unlike Bracelets (which never
 * touches a real native Hi.Events table), AuthenticateScanDevice reads
 * from the native `check_in_lists` table via DB::table() (no Eloquent
 * model, no relations) - so this base creates a minimal stand-in for it,
 * with only the columns the middleware actually reads (id, short_id,
 * event_id), alongside this module's own two Device Registry tables.
 * Runs entirely against an in-memory SQLite connection, never touches the
 * project's real Postgres database.
 */
abstract class InMemorySqliteTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'sqlite_digit_scan_test');
        config()->set('database.connections.sqlite_digit_scan_test', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);

        DB::purge('sqlite_digit_scan_test');
        DB::setDefaultConnection('sqlite_digit_scan_test');

        $this->createSchema();
    }

    private function createSchema(): void
    {
        // Minimal stand-in for the native Hi.Events table - only the
        // columns AuthenticateScanDevice actually reads via DB::table().
        Schema::connection('sqlite_digit_scan_test')->create('check_in_lists', function ($table) {
            $table->id();
            $table->string('short_id')->unique();
            $table->unsignedBigInteger('event_id');
            $table->timestamp('deleted_at')->nullable();
        });

        Schema::connection('sqlite_digit_scan_test')->create('digit_scan_devices', function ($table) {
            $table->id();
            $table->string('name');
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('event_id')->nullable();
            $table->unsignedBigInteger('check_in_list_id')->nullable();
            $table->string('token_hash')->unique();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('status')->default('ACTIVE');
            $table->boolean('all_check_in_lists')->default(false);
            $table->timestamps();
        });

        Schema::connection('sqlite_digit_scan_test')->create('digit_scan_device_check_in_lists', function ($table) {
            $table->id();
            $table->unsignedBigInteger('device_id');
            $table->unsignedBigInteger('check_in_list_id');
            $table->timestamps();
            $table->unique(['device_id', 'check_in_list_id']);
        });
    }
}
