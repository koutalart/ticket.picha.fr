<?php
// Konka Wawa : bandeau fin 1920x540 comme Halloween (événement 7) sur les 3 soirées « 72 H à Mayotte » :
// vidéo de couverture 1920x540, image de couverture au même format (image tirée de la vidéo, plus l'affiche Barakili),
// suppression de la vidéo mobile verticale (Halloween n'en a pas). Relançable.

use HiEvents\DomainObjects\Enums\ImageType;
use HiEvents\Services\Application\Handlers\Event\CoverVideo\DTO\UploadEventCoverVideoDTO;
use HiEvents\Services\Application\Handlers\Event\CoverVideo\UploadEventCoverVideoHandler;
use HiEvents\Services\Application\Handlers\Images\CreateImageHandler;
use HiEvents\Services\Application\Handlers\Images\DeleteImageHandler;
use HiEvents\Services\Application\Handlers\Images\DTO\CreateImageDTO;
use HiEvents\Services\Application\Handlers\Images\DTO\DeleteImageDTO;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

const ACCOUNT_ID = 6;
const ORGANIZER_ID = 9;
const USER_ID = 10;
const VIDEO = '/tmp/wawa-bande/wawa-72h-bande-1920x540.mp4';
const IMAGE = '/tmp/wawa-bande/wawa-72h-bande-1920x540.jpg';

foreach ([VIDEO, IMAGE] as $f) {
    if (!is_readable($f)) {
        throw new RuntimeException('Fichier manquant : '.$f);
    }
}
auth()->setUser(\HiEvents\Models\User::findOrFail(USER_ID));

foreach ([20, 21, 22] as $id) {
    $e = DB::table('events')->where('id', $id)->where('account_id', ACCOUNT_ID)->where('organizer_id', ORGANIZER_ID)->whereNull('deleted_at')->first();
    if (!$e || !str_starts_with($e->title, 'Wawa, 72 H à Mayotte : ')) {
        throw new RuntimeException('Événement '.$id.' inattendu');
    }
    app(UploadEventCoverVideoHandler::class)->handle(new UploadEventCoverVideoDTO(
        eventId: $id,
        accountId: ACCOUNT_ID,
        video: new UploadedFile(VIDEO, basename(VIDEO), 'video/mp4', null, true),
    ));
    app(CreateImageHandler::class)->handle(new CreateImageDTO(
        userId: USER_ID,
        accountId: ACCOUNT_ID,
        image: new UploadedFile(IMAGE, basename(IMAGE), 'image/jpeg', null, true),
        imageType: ImageType::EVENT_COVER,
        entityId: $id,
    ));
    foreach (DB::table('images')->where('entity_id', $id)->where('type', 'EVENT_COVER_VIDEO_MOBILE')->whereNull('deleted_at')->pluck('id') as $imageId) {
        app(DeleteImageHandler::class)->handle(new DeleteImageDTO(imageId: $imageId, userId: USER_ID, accountId: ACCOUNT_ID));
    }
    $types = DB::table('images')->where('entity_id', $id)->whereIn('type', ['EVENT_COVER', 'EVENT_COVER_VIDEO', 'EVENT_COVER_VIDEO_MOBILE'])->whereNull('deleted_at')->get(['type', 'path']);
    echo $id.' | '.$types->map(fn ($t) => $t->type.' '.basename($t->path))->implode(', ')."\n";
}
