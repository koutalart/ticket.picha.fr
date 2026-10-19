<?php
// Salon Mayotte Recrute (événement 13) : remplace la vidéo de couverture. Relançable.

use HiEvents\Services\Application\Handlers\Event\CoverVideo\DTO\UploadEventCoverVideoDTO;
use HiEvents\Services\Application\Handlers\Event\CoverVideo\UploadEventCoverVideoHandler;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

const FICHIER = '/tmp/salon-mayotte-recrute-couverture.mp4';
if (DB::table('events')->where('id', 13)->where('account_id', 7)->value('title') !== 'Salon Mayotte Recrute 2026' || !is_readable(FICHIER)) {
    throw new RuntimeException('Contexte inattendu : rien n\'est envoyé');
}
auth()->setUser(\HiEvents\Models\User::findOrFail(11));
$v = app(UploadEventCoverVideoHandler::class)->handle(new UploadEventCoverVideoDTO(
    eventId: 13,
    accountId: 7,
    video: new UploadedFile(FICHIER, basename(FICHIER), 'video/mp4', null, true),
));
echo 'Vidéo : '.$v->getPath().' | vidéos actives : '.DB::table('images')->where('entity_id', 13)->where('type', 'EVENT_COVER_VIDEO')->whereNull('deleted_at')->count()."\n";
