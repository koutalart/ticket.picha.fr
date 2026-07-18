<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('digit_scan_device_check_in_lists', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('device_id');
            $table->unsignedBigInteger('check_in_list_id');
            $table->timestamps();

            $table->unique(['device_id', 'check_in_list_id']);
            $table->index('device_id');
            $table->index('check_in_list_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('digit_scan_device_check_in_lists');
    }
};
