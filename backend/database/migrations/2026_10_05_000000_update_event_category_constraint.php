<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::statement('ALTER TABLE events DROP CONSTRAINT IF EXISTS events_category_check');

        DB::statement("
            ALTER TABLE events
            ADD CONSTRAINT events_category_check
            CHECK (
                category::text = ANY (ARRAY[
                    'SOCIAL',
                    'FOOD_DRINK',
                    'CHARITY',
                    'MUSIC',
                    'ART',
                    'COMEDY',
                    'THEATER',
                    'BUSINESS',
                    'TECH',
                    'EDUCATION',
                    'WORKSHOP',
                    'SPORTS',
                    'FESTIVAL',
                    'NIGHTLIFE',
                    'OPEN_HOUSE',
                    'SEMINAR',
                    'INTERNAL_EVENT',
                    'CONFERENCE',
                    'GENERAL_ASSEMBLY',
                    'AWARDS',
                    'CULTURE',
                    'OTHER'
                ]::text[])
            )
        ");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE events DROP CONSTRAINT IF EXISTS events_category_check');

        DB::statement("
            ALTER TABLE events
            ADD CONSTRAINT events_category_check
            CHECK (
                category::text = ANY (ARRAY[
                    'SOCIAL',
                    'FOOD_DRINK',
                    'CHARITY',
                    'MUSIC',
                    'ART',
                    'COMEDY',
                    'THEATER',
                    'BUSINESS',
                    'TECH',
                    'EDUCATION',
                    'WORKSHOP',
                    'SPORTS',
                    'FESTIVAL',
                    'NIGHTLIFE',
                    'OTHER'
                ]::text[])
            )
        ");
    }
};
