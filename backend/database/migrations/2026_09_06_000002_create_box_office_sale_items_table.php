<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('box_office_sale_items', static function (Blueprint $table) {
            $table->id();
            $table->foreignId('box_office_sale_id')->constrained('box_office_sales')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained();
            $table->foreignId('product_price_id')->constrained();
            $table->decimal('unit_amount', 14, 2);
            $table->foreignId('attendee_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index('box_office_sale_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('box_office_sale_items');
    }
};
