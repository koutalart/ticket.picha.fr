<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One HMAC signing secret per event, used to sign/verify DIGIT Bracelets QR
 * payloads (see Digit\Bracelets\Security\EventSecurityKeyService). The
 * secret is encrypted at rest via the model's `encrypted` cast (backed by
 * APP_KEY) and is never exposed to a scanning device or included in a QR
 * payload - only the resulting signature ever leaves the server.
 *
 * Generic name (not "bracelet_keys"): this signing mechanism is meant to be
 * reusable for other DIGIT-signed artifacts in the future, not just
 * bracelets.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('digit_event_security_keys', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('event_id');
            $table->text('secret');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('rotated_at')->nullable();
            $table->string('notes')->nullable();

            $table->unique('event_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('digit_event_security_keys');
    }
};
