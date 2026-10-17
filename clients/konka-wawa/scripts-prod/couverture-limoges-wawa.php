<?php
// Konka Wawa (compte 6) : vidéo de couverture motion design de Limoges (concert au ralenti, sans plan de ville).
// Passe par le même traitement que l'écran « Vidéo de couverture » (remplace la précédente s'il y en a une).

use HiEvents\Services\Application\Handlers\Event\CoverVideo\DTO\UploadEventCoverVideoDTO;
use HiEvents\Services\Application\Handlers\Event\CoverVideo\UploadEventCoverVideoHandler;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

const ORGANIZER_ID = 9;
const ACCOUNT_ID = 6;
const USER_ID = 10;
const DOSSIER = '/tmp/wawa-limoges';

$videos = [
    11 => ['Limoges', 'wawa-limoges-couverture.mp4'],
];

foreach ($videos as $eventId => [$ville, $fichier]) {
    $event = DB::table('events')->where('id', $eventId)->where('organizer_id', ORGANIZER_ID)->where('account_id', ACCOUNT_ID)->whereNull('deleted_at')->first();
    if (!$event || $event->title !== 'Wawa – 25 ans de scène · '.$ville) {
        throw new RuntimeException('Événement '.$eventId.' inattendu : rien n\'est envoyé');
    }
    if (!is_readable(DOSSIER.'/'.$fichier) || filesize(DOSSIER.'/'.$fichier) > 15 * 1024 * 1024) {
        throw new RuntimeException('Vidéo manquante ou trop lourde : '.$fichier.' : rien n\'est envoyé');
    }
}

auth()->setUser(\HiEvents\Models\User::findOrFail(USER_ID));

foreach ($videos as $eventId => [$ville, $fichier]) {
    $video = app(UploadEventCoverVideoHandler::class)->handle(new UploadEventCoverVideoDTO(
        eventId: $eventId,
        accountId: ACCOUNT_ID,
        video: new UploadedFile(DOSSIER.'/'.$fichier, $fichier, 'video/mp4', null, true),
    ));

    $actives = DB::table('images')->where('entity_id', $eventId)->where('type', 'EVENT_COVER_VIDEO')->whereNull('deleted_at')->count();
    echo $eventId.' | '.$ville.' | '.$video->getPath().' | '.round($video->getSize() / 1048576, 1).' Mo | vidéos actives : '.$actives."\n";
}
