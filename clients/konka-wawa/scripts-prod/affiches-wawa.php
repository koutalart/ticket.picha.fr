<?php
// Konka Wawa (compte 6) : affiche de chaque date de la tournée (EVENT_FLYER), affichée sous les billets.
// Même traitement que « Personnaliser la page » ; la nouvelle affiche remplace l'ancienne. Relançable.
// Ne touche ni à l'image principale (EVENT_COVER) ni à l'image de partage (EVENT_SHARE_IMAGE).

use HiEvents\DomainObjects\Enums\ImageType;
use HiEvents\Services\Application\Handlers\Images\CreateImageHandler;
use HiEvents\Services\Application\Handlers\Images\DTO\CreateImageDTO;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

const ORGANIZER_ID = 9;
const ACCOUNT_ID = 6;
const USER_ID = 10;
const DOSSIER = '/tmp/wawa-affiches';

$dates = [8 => 'Marseille', 9 => 'Colmar', 10 => 'Toulouse', 12 => 'Lyon', 11 => 'Limoges'];

foreach ($dates as $eventId => $ville) {
    $event = DB::table('events')->where('id', $eventId)->where('organizer_id', ORGANIZER_ID)->where('account_id', ACCOUNT_ID)->whereNull('deleted_at')->first();
    if (!$event || $event->title !== 'Wawa – 25 ans de scène · '.$ville) {
        throw new RuntimeException('Événement '.$eventId.' inattendu : rien n\'est envoyé');
    }
    if (!is_readable(DOSSIER.'/wawa-'.strtolower($ville).'.jpg')) {
        throw new RuntimeException('Affiche manquante : '.$ville.' : rien n\'est envoyé');
    }
}

auth()->setUser(\HiEvents\Models\User::findOrFail(USER_ID));

foreach ($dates as $eventId => $ville) {
    $fichier = DOSSIER.'/wawa-'.strtolower($ville).'.jpg';
    app(CreateImageHandler::class)->handle(new CreateImageDTO(
        userId: USER_ID,
        accountId: ACCOUNT_ID,
        image: new UploadedFile($fichier, basename($fichier), 'image/jpeg', null, true),
        imageType: ImageType::EVENT_FLYER,
        entityId: $eventId,
    ));

    $types = DB::table('images')->where('entity_id', $eventId)->where('entity_type', 'like', '%EventDomainObject')->whereNull('deleted_at')
        ->pluck('type')->sort()->implode(', ');
    echo $eventId.' | '.$ville.' | '.$types."\n";
}
