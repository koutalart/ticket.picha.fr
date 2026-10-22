<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sms_registration_confirmations', function (Blueprint $table) {
            $table->timestamp('phone_released_at')->nullable();
        });

        DB::statement("
            UPDATE sms_registration_confirmations AS later
            SET phone_released_at = NOW()
            WHERE later.status IN ('SENDING', 'SENT')
              AND EXISTS (
                  SELECT 1 FROM sms_registration_confirmations AS earlier
                  WHERE earlier.event_id = later.event_id
                    AND earlier.phone = later.phone
                    AND earlier.status IN ('SENDING', 'SENT')
                    AND earlier.id < later.id
              )
        ");

        DB::statement("
            CREATE UNIQUE INDEX sms_registration_confirmations_one_per_phone
            ON sms_registration_confirmations (event_id, phone)
            WHERE status IN ('SENDING', 'SENT') AND phone_released_at IS NULL
        ");

        Schema::table('sms_cancellation_confirmations', function (Blueprint $table) {
            $table->index(['event_id', 'phone', 'created_at'], 'sms_cancellation_confirmations_phone_wave');
        });
    }

    public function down(): void
    {
        Schema::table('sms_cancellation_confirmations', function (Blueprint $table) {
            $table->dropIndex('sms_cancellation_confirmations_phone_wave');
        });

        DB::statement('DROP INDEX IF EXISTS sms_registration_confirmations_one_per_phone');

        Schema::table('sms_registration_confirmations', function (Blueprint $table) {
            $table->dropColumn('phone_released_at');
        });
    }
};
