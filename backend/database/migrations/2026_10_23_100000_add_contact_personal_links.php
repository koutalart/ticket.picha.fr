<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 10 characters from a 31 letter alphabet (no 0/o, 1/i/l) taken from the random bytes of a v4 UUID, skipping its fixed version and variant bytes.
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION contact_public_code() RETURNS varchar AS $$
DECLARE
    alphabet constant text := 'abcdefghjkmnpqrstuvwxyz23456789';
    random_bytes bytea := decode(replace(gen_random_uuid()::text, '-', ''), 'hex');
    byte_positions constant int[] := ARRAY[0, 1, 2, 3, 4, 5, 9, 10, 11, 12];
    code text := '';
    position int;
BEGIN
    FOREACH position IN ARRAY byte_positions LOOP
        code := code || substr(alphabet, (get_byte(random_bytes, position) % 31) + 1, 1);
    END LOOP;
    RETURN code;
END
$$ LANGUAGE plpgsql VOLATILE;
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION contacts_set_public_code() RETURNS trigger AS $$
BEGIN
    IF NEW.public_code IS NULL THEN
        NEW.public_code := contact_public_code();
    END IF;
    RETURN NEW;
END
$$ LANGUAGE plpgsql;
SQL);

        DB::statement('ALTER TABLE contacts ADD COLUMN public_code varchar(16)');
        DB::statement('UPDATE contacts SET public_code = contact_public_code()');
        DB::statement('ALTER TABLE contacts ALTER COLUMN public_code SET NOT NULL');
        DB::statement('CREATE TRIGGER contacts_public_code BEFORE INSERT ON contacts FOR EACH ROW EXECUTE FUNCTION contacts_set_public_code()');
        DB::statement('CREATE UNIQUE INDEX contacts_public_code_unique ON contacts (public_code)');

        Schema::table('qr_links', function (Blueprint $table) {
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->index('contact_id');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('attributed_contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->index('attributed_contact_id');
        });

        Schema::create('contact_link_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->string('visitor_hash', 64);
            $table->boolean('is_unique')->default(false);
            $table->string('device_type', 10)->nullable();
            $table->string('os', 20)->nullable();
            $table->string('browser', 20)->nullable();
            $table->char('country_code', 2)->nullable();
            $table->string('referrer_host', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['contact_id', 'created_at']);
            $table->index(['contact_id', 'visitor_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_link_visits');

        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('attributed_contact_id');
        });

        Schema::table('qr_links', function (Blueprint $table) {
            $table->dropConstrainedForeignId('contact_id');
        });

        DB::statement('DROP INDEX IF EXISTS contacts_public_code_unique');
        DB::statement('DROP TRIGGER IF EXISTS contacts_public_code ON contacts');
        DB::statement('ALTER TABLE contacts DROP COLUMN IF EXISTS public_code');
        DB::unprepared('DROP FUNCTION IF EXISTS contacts_set_public_code()');
        DB::unprepared('DROP FUNCTION IF EXISTS contact_public_code()');
    }
};
