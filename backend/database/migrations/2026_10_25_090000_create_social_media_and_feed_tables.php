<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_media_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organizer_id')->constrained('organizers')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->string('status', 20);
            $table->string('disk', 40);
            $table->string('path', 500)->nullable();
            $table->string('original_name');
            $table->string('mime_type', 100);
            $table->string('media_type', 20);
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedBigInteger('received_bytes')->default(0);
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('video_codec', 20)->nullable();
            $table->string('audio_codec', 20)->nullable();
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('purge_after')->nullable();
            $table->timestamps();

            $table->index('organizer_id');
            $table->index('purge_after');
        });

        Schema::create('social_feed_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organizer_id')->constrained('organizers')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->string('network', 20);
            $table->string('external_id', 128);
            $table->string('media_type', 40)->nullable();
            $table->string('media_product_type', 40)->nullable();
            $table->text('caption')->nullable();
            $table->text('media_url')->nullable();
            $table->text('thumbnail_url')->nullable();
            $table->json('children')->nullable();
            $table->string('permalink', 2048)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->unsignedInteger('like_count')->default(0);
            $table->unsignedInteger('comments_count')->default(0);
            $table->foreignId('social_post_id')->nullable()->constrained('social_posts')->nullOnDelete();
            $table->timestamp('comments_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['organizer_id', 'network', 'external_id']);
            $table->index(['organizer_id', 'published_at']);
        });

        Schema::create('social_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organizer_id')->constrained('organizers')->cascadeOnDelete();
            $table->foreignId('social_feed_item_id')->constrained('social_feed_items')->cascadeOnDelete();
            $table->string('network', 20);
            $table->string('external_id', 128);
            $table->string('parent_external_id', 128)->nullable();
            $table->string('author_id', 64)->nullable();
            $table->string('author_name')->nullable();
            $table->text('message')->nullable();
            $table->unsignedInteger('like_count')->default(0);
            $table->boolean('is_from_page')->default(false);
            $table->foreignId('replied_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('commented_at')->nullable();
            $table->timestamps();

            $table->unique(['network', 'external_id']);
            $table->index(['social_feed_item_id', 'commented_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_comments');
        Schema::dropIfExists('social_feed_items');
        Schema::dropIfExists('social_media_files');
    }
};
