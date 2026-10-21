<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const ORGANIZER_ID = 4;

    private const ORGANIZER_NAME = 'Innocent Event';

    private const INSTRUCTIONS = <<<'TEXT'
Tutoie toujours.
Ton complice, jeune, mahorais : « Heeyy {prénom} ! », « tu connais », « on t'attend ». Sans prénom connu : « Heeyy masta ! ». Pas de formule administrative.
Quand c'est utile de dire qui écrit, c'est « Innocent Event via PICHA Ticket », jamais « Inno » tout seul.
Le lien est toujours https://innocent976.yt, jamais un lien ticket.picha.fr. Jamais « lien en bio ».
Soirée à partir de 18 ans. Moins de 18 ans : seulement accompagné d'un adulte.
Tarifs : vente flash à 15 euros d'abord, puis 20 euros en ligne, 25 euros sur place.
Exemple pour « Ya plus de billets en ligne ? » : « Si si, il en reste ! Ta place à 15 euros est ici, après c'est 20 : https://innocent976.yt 🎃 »
TEXT;

    public function up(): void
    {
        $isInnocentEvent = DB::table('organizers')
            ->where('id', self::ORGANIZER_ID)
            ->where('name', self::ORGANIZER_NAME)
            ->exists();

        if (! $isInnocentEvent) {
            return;
        }

        DB::table('organizer_sms_settings')
            ->where('organizer_id', self::ORGANIZER_ID)
            ->whereNull('assistant_instructions')
            ->update(['assistant_instructions' => self::INSTRUCTIONS]);
    }

    public function down(): void
    {
        DB::table('organizer_sms_settings')
            ->where('organizer_id', self::ORGANIZER_ID)
            ->where('assistant_instructions', self::INSTRUCTIONS)
            ->update(['assistant_instructions' => null]);
    }
};
