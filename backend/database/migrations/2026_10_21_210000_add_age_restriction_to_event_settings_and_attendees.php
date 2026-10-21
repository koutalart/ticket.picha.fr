<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_settings', function (Blueprint $table) {
            $table->string('age_restriction_rule', 30)->default('NONE');
            $table->unsignedSmallInteger('age_restriction_minimum_age')->default(18);
        });

        Schema::table('attendees', function (Blueprint $table) {
            $table->date('birth_date')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('event_settings', function (Blueprint $table) {
            $table->dropColumn(['age_restriction_rule', 'age_restriction_minimum_age']);
        });

        Schema::table('attendees', function (Blueprint $table) {
            $table->dropColumn('birth_date');
        });
    }
};
