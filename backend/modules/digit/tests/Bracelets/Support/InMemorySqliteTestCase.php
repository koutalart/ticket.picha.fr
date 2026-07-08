<?php

declare(strict_types=1);

namespace Digit\Tests\Bracelets\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Base for Bracelets Feature tests. Runs entirely against an in-memory
 * SQLite connection containing ONLY this module's own two tables
 * (digit_bracelets, digit_event_security_keys) - never touches the
 * project's real Postgres database, and requires no native Hi.Events
 * table or factory, because BraceletGenerationService,
 * BraceletAssociationService and BraceletLookupService only ever read/write
 * these two tables directly (event_id/account_id/attendee_id are plain
 * integers here, exactly as they are in production - this module never
 * declares a real FK constraint toward native tables, see the migrations).
 *
 * NOTE: these tests boot the full Laravel application (via Tests\TestCase)
 * but override the default DB connection before each test, so they do not
 * require the project's configured Postgres test database to be reachable.
 */
abstract class InMemorySqliteTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'sqlite_digit_bracelets_test');
        config()->set('database.connections.sqlite_digit_bracelets_test', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);

        DB::purge('sqlite_digit_bracelets_test');
        DB::setDefaultConnection('sqlite_digit_bracelets_test');

        $this->createSchema();
    }

    private function createSchema(): void
    {
        Schema::connection('sqlite_digit_bracelets_test')->create('digit_event_security_keys', function ($table) {
            $table->id();
            $table->unsignedBigInteger('event_id');
            $table->text('secret');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('rotated_at')->nullable();
            $table->string('notes')->nullable();
            $table->unique('event_id');
        });

        Schema::connection('sqlite_digit_bracelets_test')->create('digit_bracelets', function ($table) {
            $table->id();
            $table->string('code')->unique();
            $table->unsignedBigInteger('event_id');
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('attendee_id')->nullable();
            $table->string('batch_label')->nullable();
            $table->string('status')->default('GENERATED');
            $table->timestamp('printed_at')->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('compromised_at')->nullable();
            $table->timestamps();
            $table->index('event_id');
            $table->index('attendee_id');
            $table->index('status');
        });

        // SQLite supports partial unique indexes without CONCURRENTLY -
        // mirrors the intent of the Postgres migration
        // (digit_bracelets_unique_active_attendee), not its exact syntax.
        DB::connection('sqlite_digit_bracelets_test')->statement(<<<SQL
            CREATE UNIQUE INDEX digit_bracelets_unique_active_attendee
            ON digit_bracelets (attendee_id)
            WHERE attendee_id IS NOT NULL AND status NOT IN ('REVOKED', 'COMPROMISED')
        SQL);
    }
}
