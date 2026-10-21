<?php
// Mayotte la 1ère (compte 5) : publication du 22/10 validée par Jo.
// Événement 14 : vidéo d'en-tête ordinateur (1080p, 7,3 Mo) et mobile (720p, 3,9 Mo), PR #176.

use HiEvents\Services\Application\Handlers\Event\CoverVideo\DTO\UploadEventCoverVideoDTO;
use HiEvents\Services\Application\Handlers\Event\CoverVideo\UploadEventCoverVideoHandler;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

const DOSSIER = '/tmp/publication';
$event = DB::table('events')->where('id', 14)->where('account_id', 5)->where('organizer_id', 8)->whereNull('deleted_at')->first();
if (!$event || $event->title !== 'Le Concert des Générations'
    || !is_readable(DOSSIER.'/concert-entete-bureau.mp4') || !is_readable(DOSSIER.'/concert-entete-mobile.mp4')) {
    throw new RuntimeException('Événement ou fichiers inattendus : rien n\'est modifié');
}
auth()->setUser(\HiEvents\Models\User::findOrFail(8));

foreach (['concert-entete-bureau.mp4' => false, 'concert-entete-mobile.mp4' => true] as $fichier => $mobile) {
    app(UploadEventCoverVideoHandler::class)->handle(new UploadEventCoverVideoDTO(
        eventId: 14,
        accountId: 5,
        video: new UploadedFile(DOSSIER.'/'.$fichier, 'concert-des-generations'.($mobile ? '-mobile' : '').'.mp4', 'video/mp4', null, true),
        mobile: $mobile,
    ));
}

foreach (DB::table('images')->where('entity_id', 14)->whereIn('type', ['EVENT_COVER_VIDEO', 'EVENT_COVER_VIDEO_MOBILE'])->whereNull('deleted_at')->get(['type', 'path', 'size']) as $i) {
    echo $i->type.' | '.$i->path.' | '.round($i->size / 1048576, 1)." Mo\n";
}
