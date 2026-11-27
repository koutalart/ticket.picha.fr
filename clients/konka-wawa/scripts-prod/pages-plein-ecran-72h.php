<?php
// Konka Wawa (compte 6) : les 3 soirées « 72 H à Mayotte » passent sur la page événement plein écran
// (comme le Concert des Générations) : titre en grand, vidéo du site en fond (ordinateur + mobile).
// Relançable : réglages remis à la même valeur, vidéos remplacées.

use HiEvents\Repository\Interfaces\EventSettingsRepositoryInterface;
use HiEvents\Services\Application\Handlers\Event\CoverVideo\DTO\UploadEventCoverVideoDTO;
use HiEvents\Services\Application\Handlers\Event\CoverVideo\UploadEventCoverVideoHandler;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

const ACCOUNT_ID = 6;
const ORGANIZER_ID = 9;
const USER_ID = 10;
const VIDEO = '/tmp/wawa-72h/fond-72h.mp4';
const VIDEO_MOBILE = '/tmp/wawa-72h/fond-72h-mobile.mp4';

$soirees = [
    20 => ['Wawa, 72 H à Mayotte : 5/5', "Wawa\n5/5"],
    21 => ['Wawa, 72 H à Mayotte : Ocean Bambo', "Wawa\nOcean Bambo"],
    22 => ['Wawa, 72 H à Mayotte : La Marine', "Wawa\nLa Marine"],
];

foreach ($soirees as $id => [$titre]) {
    $e = DB::table('events')->where('id', $id)->where('account_id', ACCOUNT_ID)->where('organizer_id', ORGANIZER_ID)->whereNull('deleted_at')->first();
    if (!$e || $e->title !== $titre) {
        throw new RuntimeException('Événement '.$id.' inattendu : rien n\'est modifié');
    }
}
foreach ([VIDEO, VIDEO_MOBILE] as $v) {
    if (!is_readable($v) || filesize($v) > 15 * 1024 * 1024) {
        throw new RuntimeException('Vidéo manquante ou trop lourde : '.$v);
    }
}

auth()->setUser(\HiEvents\Models\User::findOrFail(USER_ID));

DB::transaction(function () use ($soirees) {
    foreach ($soirees as $id => [, $hero]) {
        $theme = json_decode((string) DB::table('event_settings')->where('event_id', $id)->value('homepage_theme_settings'), true) ?: [];
        $theme = array_merge($theme, [
            'mode' => 'dark',
            'accent' => '#ff2d95',
            'background' => '#120a1f',
            'background_type' => 'COLOR',
            'hero_layout' => 'FULLSCREEN',
            'hero_title' => $hero,
            'hero_subtitle' => '',
            'hero_tagline' => '',
        ]);
        app(EventSettingsRepositoryInterface::class)->updateWhere(
            attributes: ['homepage_theme_settings' => json_encode($theme, JSON_UNESCAPED_UNICODE)],
            where: ['event_id' => $id],
        );
    }
});

foreach (array_keys($soirees) as $id) {
    foreach ([VIDEO => false, VIDEO_MOBILE => true] as $fichier => $mobile) {
        app(UploadEventCoverVideoHandler::class)->handle(new UploadEventCoverVideoDTO(
            eventId: $id,
            accountId: ACCOUNT_ID,
            video: new UploadedFile($fichier, basename($fichier), 'video/mp4', null, true),
            mobile: $mobile,
        ));
    }
    $t = json_decode((string) DB::table('event_settings')->where('event_id', $id)->value('homepage_theme_settings'), true);
    $v = DB::table('images')->where('entity_id', $id)->whereIn('type', ['EVENT_COVER_VIDEO', 'EVENT_COVER_VIDEO_MOBILE'])->whereNull('deleted_at')->pluck('type')->implode(', ');
    echo $id.' | '.$t['hero_layout'].' | '.str_replace("\n", ' / ', $t['hero_title']).' | vidéos : '.$v."\n";
}
