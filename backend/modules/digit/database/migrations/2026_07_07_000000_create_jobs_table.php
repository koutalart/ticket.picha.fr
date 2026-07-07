<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Standard Laravel "database" queue driver table. Hi.Events ships
 * migrations for job_batches and failed_jobs but never actually added
 * this one, because it has always used the redis queue connection.
 *
 * We need it as part of the Module 3 hardening fix: Hi.Events' own core
 * check-in handler (CreateAttendeeCheckInPublicHandler) dispatches a
 * CheckinEvent whose listener synchronously pushes a webhook job onto the
 * default queue connection, DURING the same request/transaction that
 * writes the check-in row. The redis connection is configured with
 * after_commit => true, so Laravel defers that push until the surrounding
 * transaction commits - and if Redis is unreachable at that moment, the
 * push throws and the whole transaction (check-in row included) rolls
 * back. Switching QUEUE_CONNECTION to "database" removes Redis from this
 * path entirely: enqueueing a job becomes a plain Postgres insert in the
 * same connection/transaction as everything else, so it can never fail
 * due to a Redis outage. This is a schema addition only - no core
 * Hi.Events file is modified.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('jobs')) {
            return;
        }

        Schema::create('jobs', function (Blueprint $table) {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jobs');
    }
};
