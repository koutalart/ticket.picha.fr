<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizers', static function (Blueprint $table) {
            $table->jsonb('site_content')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('organizers', static function (Blueprint $table) {
            $table->dropColumn('site_content');
        });
    }
};
