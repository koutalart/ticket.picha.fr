<?php
// Konka Wawa (compte 6) : les 3 soirées « 72 H à Mayotte » sur le modèle de la page Halloween d'Innocent (événement 7) :
// carte claire, vidéo dans le bandeau, affiche floutée en fond, police Outfit. Accent Wawa conservé.
// Annule le plein écran du 27/11 matin. Vidéos de couverture déjà en place. Relançable.

use HiEvents\Repository\Interfaces\EventSettingsRepositoryInterface;
use Illuminate\Support\Facades\DB;

const ACCOUNT_ID = 6;
const ORGANIZER_ID = 9;

$soirees = [20 => 'Wawa, 72 H à Mayotte : 5/5', 21 => 'Wawa, 72 H à Mayotte : Ocean Bambo', 22 => 'Wawa, 72 H à Mayotte : La Marine'];

foreach ($soirees as $id => $titre) {
    $e = DB::table('events')->where('id', $id)->where('account_id', ACCOUNT_ID)->where('organizer_id', ORGANIZER_ID)->whereNull('deleted_at')->first();
    if (!$e || $e->title !== $titre) {
        throw new RuntimeException('Événement '.$id.' inattendu : rien n\'est modifié');
    }
}

DB::transaction(function () use ($soirees) {
    foreach (array_keys($soirees) as $id) {
        $theme = [
            'mode' => 'light',
            'accent' => '#ff2d95',
            'background' => '#171717',
            'font_family' => 'Outfit',
            'hero_layout' => 'CARD',
            'background_type' => 'MIRROR_COVER_IMAGE',
        ];
        app(EventSettingsRepositoryInterface::class)->updateWhere(
            attributes: ['homepage_theme_settings' => json_encode($theme, JSON_UNESCAPED_UNICODE)],
            where: ['event_id' => $id],
        );
    }
});

foreach (array_keys($soirees) as $id) {
    $v = DB::table('images')->where('entity_id', $id)->whereIn('type', ['EVENT_COVER', 'EVENT_COVER_VIDEO'])->whereNull('deleted_at')->pluck('type')->implode(', ');
    echo $id.' | '.DB::table('event_settings')->where('event_id', $id)->value('homepage_theme_settings').' | '.$v."\n";
}
