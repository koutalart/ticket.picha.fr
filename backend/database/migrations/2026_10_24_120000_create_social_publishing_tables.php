<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('organizer_social_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organizer_id')->unique()->constrained('organizers')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->string('provider', 20)->default('META');
            $table->string('status', 30);
            $table->string('facebook_user_id', 64)->nullable();
            $table->text('user_access_token')->nullable();
            $table->timestamp('user_access_token_expires_at')->nullable();
            $table->string('facebook_page_id', 64);
            $table->string('facebook_page_name')->nullable();
            $table->text('page_access_token');
            $table->string('instagram_account_id', 64)->nullable();
            $table->string('instagram_username')->nullable();
            $table->json('granted_scopes')->nullable();
            $table->foreignId('connected_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('last_error')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->timestamps();
        });

        Schema::create('social_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organizer_id')->constrained('organizers')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->string('network', 20);
            $table->string('format', 20);
            $table->text('caption')->nullable();
            $table->json('media')->nullable();
            $table->string('link', 2048)->nullable();
            $table->string('status', 30);
            $table->string('source', 20)->default('PICHA');
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->string('external_container_id', 64)->nullable();
            $table->string('external_id', 128)->nullable();
            $table->string('permalink', 2048)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->text('last_error')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'next_attempt_at']);
            $table->index(['organizer_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_posts');
        Schema::dropIfExists('organizer_social_connections');
    }
};
