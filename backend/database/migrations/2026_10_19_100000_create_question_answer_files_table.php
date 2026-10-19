<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('question_answer_files', static function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events');
            $table->foreignId('order_id')->constrained('orders');
            $table->foreignId('question_id')->constrained('questions');
            $table->foreignId('question_answer_id')->nullable()->constrained('question_answers')->nullOnDelete();
            $table->string('disk', 50);
            $table->string('path');
            $table->string('original_filename');
            $table->string('mime_type', 100);
            $table->unsignedInteger('size');
            $table->timestamps();
            $table->softDeletes();

            $table->index('order_id');
            $table->index('question_answer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('question_answer_files');
    }
};
