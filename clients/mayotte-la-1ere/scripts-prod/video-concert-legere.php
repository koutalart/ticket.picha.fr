<?php
// Concert des Générations (compte 5, événement 14) : vidéo d'en-tête allégée (Higgsfield ByteDance, 720p N&B, 90 %, ~3,9 Mo). Relançable.

use HiEvents\Services\Application\Handlers\Event\CoverVideo\DTO\UploadEventCoverVideoDTO;
use HiEvents\Services\Application\Handlers\Event\CoverVideo\UploadEventCoverVideoHandler;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

const FICHIER = '/tmp/concert-entete-mobile.mp4';
$event = DB::table('events')->where('id', 14)->where('account_id', 5)->where('organizer_id', 8)->whereNull('deleted_at')->first();
if (!$event || $event->title !== 'Le Concert des Générations' || !is_readable(FICHIER)) {
    throw new RuntimeException('Événement ou fichier inattendu : rien n\'est envoyé');
}
auth()->setUser(\HiEvents\Models\User::findOrFail(8));
$v = app(UploadEventCoverVideoHandler::class)->handle(new UploadEventCoverVideoDTO(
    eventId: 14,
    accountId: 5,
    video: new UploadedFile(FICHIER, 'concert-des-generations.mp4', 'video/mp4', null, true),
));
echo 'Vidéo | '.$v->getPath().' | '.round($v->getSize() / 1048576, 1).' Mo | actives : '
    .DB::table('images')->where('entity_id', 14)->where('type', 'EVENT_COVER_VIDEO')->whereNull('deleted_at')->count()."\n";
