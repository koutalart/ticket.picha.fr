<?php
// Konka Wawa (compte 6) : habillage néon pour Marseille, Colmar, Toulouse et Lyon.
// Pour chaque date : miniature (image principale + image de partage) et vidéo de couverture.
// Passe par les mêmes traitements que l'interface ; relancé, il remplace chaque fichier par le même.

use HiEvents\DomainObjects\Enums\ImageType;
use HiEvents\Services\Application\Handlers\Event\CoverVideo\DTO\UploadEventCoverVideoDTO;
use HiEvents\Services\Application\Handlers\Event\CoverVideo\UploadEventCoverVideoHandler;
use HiEvents\Services\Application\Handlers\Images\CreateImageHandler;
use HiEvents\Services\Application\Handlers\Images\DTO\CreateImageDTO;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

const ORGANIZER_ID = 9;
const ACCOUNT_ID = 6;
const USER_ID = 10;
const DOSSIER = '/tmp/wawa-neon';

$dates = [
    8 => ['Marseille', 'marseille'],
    9 => ['Colmar', 'colmar'],
    10 => ['Toulouse', 'toulouse'],
    12 => ['Lyon', 'lyon'],
];

foreach ($dates as $eventId => [$ville, $slug]) {
    $event = DB::table('events')->where('id', $eventId)->where('organizer_id', ORGANIZER_ID)->where('account_id', ACCOUNT_ID)->whereNull('deleted_at')->first();
    if (!$event || $event->title !== 'Wawa – 25 ans de scène · '.$ville) {
        throw new RuntimeException('Événement '.$eventId.' inattendu : rien n\'est envoyé');
    }
    foreach (["wawa-$slug-miniature.jpg", "wawa-$slug-couverture.mp4"] as $fichier) {
        if (!is_readable(DOSSIER.'/'.$fichier)) {
            throw new RuntimeException('Fichier manquant : '.$fichier.' : rien n\'est envoyé');
        }
    }
}

auth()->setUser(\HiEvents\Models\User::findOrFail(USER_ID));

foreach ($dates as $eventId => [$ville, $slug]) {
    $miniature = DOSSIER."/wawa-$slug-miniature.jpg";
    foreach ([ImageType::EVENT_COVER, ImageType::EVENT_SHARE_IMAGE] as $type) {
        app(CreateImageHandler::class)->handle(new CreateImageDTO(
            userId: USER_ID,
            accountId: ACCOUNT_ID,
            image: new UploadedFile($miniature, basename($miniature), 'image/jpeg', null, true),
            imageType: $type,
            entityId: $eventId,
        ));
    }

    $video = DOSSIER."/wawa-$slug-couverture.mp4";
    app(UploadEventCoverVideoHandler::class)->handle(new UploadEventCoverVideoDTO(
        eventId: $eventId,
        accountId: ACCOUNT_ID,
        video: new UploadedFile($video, basename($video), 'video/mp4', null, true),
    ));

    $actifs = DB::table('images')->where('entity_id', $eventId)->where('entity_type', 'like', '%EventDomainObject')
        ->whereIn('type', ['EVENT_COVER', 'EVENT_SHARE_IMAGE', 'EVENT_COVER_VIDEO'])->whereNull('deleted_at')
        ->pluck('type')->sort()->implode(', ');
    echo $eventId.' | '.$ville.' | '.$actifs."\n";
}
