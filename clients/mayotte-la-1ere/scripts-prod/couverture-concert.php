<?php
// Concert des Générations (compte 5, événement 14) : couverture en image principale et image de partage. Relançable.

use HiEvents\DomainObjects\Enums\ImageType;
use HiEvents\Services\Application\Handlers\Images\CreateImageHandler;
use HiEvents\Services\Application\Handlers\Images\DTO\CreateImageDTO;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

const ACCOUNT_ID = 5;
const EVENT_ID = 14;
const USER_ID = 8;
const FICHIER = '/tmp/concert-couverture.jpg';

$event = DB::table('events')->where('id', EVENT_ID)->where('account_id', ACCOUNT_ID)->where('organizer_id', 8)->whereNull('deleted_at')->first();
if (!$event || $event->title !== 'Le Concert des Générations' || !is_readable(FICHIER)) {
    throw new RuntimeException('Événement ou fichier inattendu : rien n\'est envoyé');
}

auth()->setUser(\HiEvents\Models\User::findOrFail(USER_ID));

foreach ([ImageType::EVENT_COVER, ImageType::EVENT_SHARE_IMAGE] as $type) {
    app(CreateImageHandler::class)->handle(new CreateImageDTO(
        userId: USER_ID,
        accountId: ACCOUNT_ID,
        image: new UploadedFile(FICHIER, 'concert-des-generations.jpg', 'image/jpeg', null, true),
        imageType: $type,
        entityId: EVENT_ID,
    ));
}

echo 'Événement 14 | images : '.DB::table('images')->where('entity_id', EVENT_ID)->where('entity_type', 'like', '%EventDomainObject')
    ->whereNull('deleted_at')->pluck('type')->sort()->implode(', ')."\n";
