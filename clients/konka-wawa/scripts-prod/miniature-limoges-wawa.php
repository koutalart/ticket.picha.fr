<?php
// Konka Wawa (compte 6) : miniature néon de Limoges (image principale + image de partage).
// Même traitement que l'interface ; remplace les images précédentes. Relançable.

use HiEvents\DomainObjects\Enums\ImageType;
use HiEvents\Services\Application\Handlers\Images\CreateImageHandler;
use HiEvents\Services\Application\Handlers\Images\DTO\CreateImageDTO;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

const ORGANIZER_ID = 9;
const ACCOUNT_ID = 6;
const USER_ID = 10;
const EVENT_ID = 11;
const FICHIER = '/tmp/wawa-limoges-mini/wawa-limoges-miniature.jpg';

$event = DB::table('events')->where('id', EVENT_ID)->where('organizer_id', ORGANIZER_ID)->where('account_id', ACCOUNT_ID)->whereNull('deleted_at')->first();
if (!$event || $event->title !== 'Wawa – 25 ans de scène · Limoges' || !is_readable(FICHIER)) {
    throw new RuntimeException('Événement ou miniature inattendu : rien n\'est envoyé');
}

auth()->setUser(\HiEvents\Models\User::findOrFail(USER_ID));

foreach ([ImageType::EVENT_COVER, ImageType::EVENT_SHARE_IMAGE] as $type) {
    app(CreateImageHandler::class)->handle(new CreateImageDTO(
        userId: USER_ID,
        accountId: ACCOUNT_ID,
        image: new UploadedFile(FICHIER, basename(FICHIER), 'image/jpeg', null, true),
        imageType: $type,
        entityId: EVENT_ID,
    ));
}

echo '11 | Limoges | '.DB::table('images')->where('entity_id', EVENT_ID)->where('entity_type', 'like', '%EventDomainObject')->whereNull('deleted_at')->pluck('type')->sort()->implode(', ')."\n";
