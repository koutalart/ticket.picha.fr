<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PICHA (06/11): a hand-written SEO text only applies while the event page and its tickets did not change after it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_settings', function (Blueprint $table) {
            $table->timestamp('seo_updated_at')->nullable()->after('seo_keywords');
        });

        DB::table('event_settings')
            ->where(fn ($query) => $query->whereNotNull('seo_title')->orWhereNotNull('seo_description')->orWhereNotNull('seo_keywords'))
            ->update(['seo_updated_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('event_settings', function (Blueprint $table) {
            $table->dropColumn('seo_updated_at');
        });
    }
};
