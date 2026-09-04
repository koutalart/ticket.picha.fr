<?php

declare(strict_types=1);

namespace Digit\Tests\Devices\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Base for Devices (Device Registry) tests. Covers the four tables owned
 * by this domain (digit_scan_devices, digit_scan_device_check_in_lists,
 * digit_scan_device_activation_codes, digit_scan_device_audit_log),
 * shared across DeviceLifecycleServiceTest, DeviceActivationCodeServiceTest,
 * DeviceCheckInListAssignmentServiceTest and the CLI commands Feature test,
 * so schema creation isn't duplicated four times. Runs entirely against an
 * in-memory SQLite connection, never touches the project's real Postgres
 * database.
 */
abstract class InMemorySqliteTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'sqlite_digit_devices_test');
        config()->set('database.connections.sqlite_digit_devices_test', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);

        DB::purge('sqlite_digit_devices_test');
        DB::setDefaultConnection('sqlite_digit_devices_test');

        $this->createSchema();
    }

    private function createSchema(): void
    {
        Schema::connection('sqlite_digit_devices_test')->create('digit_scan_devices', function ($table) {
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

        Schema::connection('sqlite_digit_devices_test')->create('digit_scan_device_check_in_lists', function ($table) {
            $table->id();
            $table->unsignedBigInteger('device_id');
            $table->unsignedBigInteger('check_in_list_id');
            $table->timestamps();
            $table->unique(['device_id', 'check_in_list_id']);
        });

        Schema::connection('sqlite_digit_devices_test')->create('digit_scan_device_activation_codes', function ($table) {
            $table->id();
            $table->unsignedBigInteger('device_id');
            $table->string('code', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });

        Schema::connection('sqlite_digit_devices_test')->create('digit_scan_device_audit_log', function ($table) {
            $table->id();
            $table->unsignedBigInteger('device_id');
            $table->string('action');
            $table->string('performed_by')->nullable();
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->timestamp('created_at');
        });
    }
}
