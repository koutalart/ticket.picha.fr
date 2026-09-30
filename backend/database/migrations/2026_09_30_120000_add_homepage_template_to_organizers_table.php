<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizers', static function (Blueprint $table) {
            $table->string('homepage_template', 50)->default('DEFAULT');
        });
    }

    public function down(): void
    {
        Schema::table('organizers', static function (Blueprint $table) {
            $table->dropColumn('homepage_template');
        });
    }
};
