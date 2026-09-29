<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('box_office_sales', static function (Blueprint $table) {
            $table->string('phone', 25)->nullable()->after('amount_collected');
        });
    }

    public function down(): void
    {
        Schema::table('box_office_sales', static function (Blueprint $table) {
            $table->dropColumn('phone');
        });
    }
};
