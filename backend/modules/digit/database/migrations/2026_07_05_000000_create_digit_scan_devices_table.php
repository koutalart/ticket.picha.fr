<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('digit_scan_devices', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('event_id')->nullable();
            $table->unsignedBigInteger('check_in_list_id')->nullable();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['account_id']);
            $table->index(['check_in_list_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('digit_scan_devices');
    }
};
