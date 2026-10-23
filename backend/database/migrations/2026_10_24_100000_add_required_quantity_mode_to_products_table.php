<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('products', static function (Blueprint $table) {
            $table->string('required_quantity_mode', 20)->default('PER_ORDER');
        });
    }

    public function down(): void
    {
        Schema::table('products', static function (Blueprint $table) {
            $table->dropColumn('required_quantity_mode');
        });
    }
};
