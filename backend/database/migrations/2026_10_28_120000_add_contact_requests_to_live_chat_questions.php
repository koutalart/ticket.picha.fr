<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('live_chat_questions', function (Blueprint $table) {
            $table->unsignedSmallInteger('contact_requests')->default(0)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('live_chat_questions', function (Blueprint $table) {
            $table->dropColumn('contact_requests');
        });
    }
};
