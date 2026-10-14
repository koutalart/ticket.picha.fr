<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organizer_id')->constrained('organizers')->onDelete('cascade');
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->string('file_name', 255);
            $table->string('status', 50);
            $table->jsonb('column_mapping')->nullable();
            $table->string('tag_name', 100)->nullable();
            $table->boolean('email_consent_declared')->default(false);
            $table->boolean('sms_consent_declared')->default(false);
            $table->integer('created_count')->default(0);
            $table->integer('updated_count')->default(0);
            $table->integer('rejected_count')->default(0);
            $table->jsonb('rejected_rows')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('reverted_at')->nullable();
            $table->timestamps();

            $table->index('organizer_id');
        });

        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organizer_id')->constrained('organizers')->onDelete('cascade');
            $table->string('first_name', 255)->nullable();
            $table->string('last_name', 255)->nullable();
            $table->string('email', 255)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('source', 20);
            $table->foreignId('contact_import_id')->nullable()->constrained('contact_imports')->onDelete('set null');
            $table->foreignId('merged_into_contact_id')->nullable()->constrained('contacts')->onDelete('cascade');

            $table->timestamp('email_consent_at')->nullable();
            $table->string('email_consent_source', 20)->nullable();
            $table->timestamp('email_unsubscribed_at')->nullable();
            $table->timestamp('sms_consent_at')->nullable();
            $table->string('sms_consent_source', 20)->nullable();
            $table->timestamp('sms_unsubscribed_at')->nullable();

            $table->integer('orders_count')->default(0);
            $table->integer('events_count')->default(0);
            $table->integer('attended_events_count')->default(0);
            $table->decimal('total_spent', 14, 2)->default(0);
            $table->timestamp('first_order_at')->nullable();
            $table->timestamp('last_order_at')->nullable();
            $table->timestamp('last_synced_at', 6)->nullable();
            $table->timestamps();

            $table->index(['organizer_id', 'last_order_at']);
            $table->index(['organizer_id', 'phone']);
            $table->index('merged_into_contact_id');
        });

        DB::statement('CREATE UNIQUE INDEX contacts_organizer_email_unique ON contacts (organizer_id, email) WHERE email IS NOT NULL');

        Schema::create('contact_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained('contacts')->onDelete('cascade');
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->text('note');
            $table->timestamps();
            $table->softDeletes();

            $table->index('contact_id');
        });

        Schema::create('contact_tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organizer_id')->constrained('organizers')->onDelete('cascade');
            $table->string('name', 100);
            $table->timestamps();
        });

        DB::statement('CREATE UNIQUE INDEX contact_tags_organizer_name_unique ON contact_tags (organizer_id, lower(name))');

        Schema::create('contact_tag_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained('contacts')->onDelete('cascade');
            $table->foreignId('contact_tag_id')->constrained('contact_tags')->onDelete('cascade');
            $table->timestamps();

            $table->unique(['contact_id', 'contact_tag_id']);
            $table->index('contact_tag_id');
        });

        DB::statement('CREATE INDEX IF NOT EXISTS orders_lower_email_index ON orders (lower(email))');
        DB::statement('CREATE INDEX IF NOT EXISTS attendees_lower_email_index ON attendees (lower(email))');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS orders_lower_email_index');
        DB::statement('DROP INDEX IF EXISTS attendees_lower_email_index');
        Schema::dropIfExists('contact_tag_assignments');
        Schema::dropIfExists('contact_tags');
        Schema::dropIfExists('contact_notes');
        Schema::dropIfExists('contacts');
        Schema::dropIfExists('contact_imports');
    }
};
