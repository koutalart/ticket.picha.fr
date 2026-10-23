<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizer_newsletter_subscribers', function (Blueprint $table) {
            $table->string('source', 20)->default('FOOTER')->after('email');
            $table->foreignId('order_id')->nullable()->after('event_id')->constrained('orders')->nullOnDelete();
            $table->timestamp('unsubscribed_at')->nullable()->after('consented_at');
        });
    }

    public function down(): void
    {
        Schema::table('organizer_newsletter_subscribers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('order_id');
            $table->dropColumn(['source', 'unsubscribed_at']);
        });
    }
};
