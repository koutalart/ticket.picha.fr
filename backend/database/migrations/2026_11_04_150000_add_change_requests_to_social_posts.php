<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_posts', function (Blueprint $table) {
            $table->text('change_request_comment')->nullable();
            $table->timestamp('change_requested_at')->nullable();
            $table->foreignId('change_requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('replaced_by_post_id')->nullable()->constrained('social_posts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('social_posts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('replaced_by_post_id');
            $table->dropConstrainedForeignId('change_requested_by_user_id');
            $table->dropColumn(['change_request_comment', 'change_requested_at']);
        });
    }
};
