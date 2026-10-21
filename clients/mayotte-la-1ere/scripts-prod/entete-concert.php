<?php
// Concert des Générations (compte 5, événement 14) : en-tête plein écran (PR #163).
// Réglages validés avec les règles du formulaire « Design de la page », logos déposés comme un organisateur.
// La vidéo de couverture est envoyée si /tmp/entete/concert-entete-hd-90-nb.mp4 est présente. Relançable.

use HiEvents\DomainObjects\Enums\ImageType;
use HiEvents\Http\Request\EventSettings\UpdateEventSettingsRequest;
use HiEvents\Services\Application\Handlers\Event\CoverVideo\DTO\UploadEventCoverVideoDTO;
use HiEvents\Services\Application\Handlers\Event\CoverVideo\UploadEventCoverVideoHandler;
use HiEvents\Services\Application\Handlers\EventSettings\DTO\PartialUpdateEventSettingsDTO;
use HiEvents\Services\Application\Handlers\EventSettings\PartialUpdateEventSettingsHandler;
use HiEvents\Services\Application\Handlers\Images\CreateImageHandler;
use HiEvents\Services\Application\Handlers\Images\DTO\CreateImageDTO;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

const ACCOUNT_ID = 5;
const EVENT_ID = 14;
const USER_ID = 8;
const DOSSIER = '/tmp/entete';

$theme = [
    'mode' => 'dark',
    'accent' => '#b8963e',
    'background' => '#0f1830',
    'background_type' => 'COLOR',
    'font_family' => 'Montserrat',
    'hero_layout' => 'FULLSCREEN',
    'hero_title' => "Le\nConcert des\nGénérations",
    'hero_subtitle' => 'Cérémonie de remise des trophées aux Chama',
    'hero_tagline' => 'Célébrées avec',
];
$logos = [
    'EVENT_HERO_PARTNER_LOGO' => '40ans-tv-mayotte-detoure-blanc.png',
    'EVENT_HERO_BRAND_LOGO' => 'mayotte-la-1ere-blanc-recadre.png',
    'EVENT_HERO_SIGNATURE_LOGO' => 'kulturm-detoure-blanc.png',
];

$event = DB::table('events')->where('id', EVENT_ID)->where('account_id', ACCOUNT_ID)->where('organizer_id', 8)->whereNull('deleted_at')->first();
if (!$event || $event->title !== 'Le Concert des Générations') {
    throw new RuntimeException('Événement inattendu : rien n\'est modifié');
}
foreach ($logos as $fichier) {
    if (!is_readable(DOSSIER.'/'.$fichier)) {
        throw new RuntimeException('Logo manquant : '.$fichier.' : rien n\'est modifié');
    }
}
$regles = array_filter((new UpdateEventSettingsRequest())->rules(), fn ($cle) => str_starts_with($cle, 'homepage_theme_settings'), ARRAY_FILTER_USE_KEY);
Validator::make(['homepage_theme_settings' => $theme], $regles)->validate();

auth()->setUser(\HiEvents\Models\User::findOrFail(USER_ID));

app(PartialUpdateEventSettingsHandler::class)->handle(new PartialUpdateEventSettingsDTO(
    account_id: ACCOUNT_ID,
    event_id: EVENT_ID,
    settings: ['homepage_theme_settings' => $theme],
));

foreach ($logos as $type => $fichier) {
    app(CreateImageHandler::class)->handle(new CreateImageDTO(
        userId: USER_ID,
        accountId: ACCOUNT_ID,
        image: new UploadedFile(DOSSIER.'/'.$fichier, $fichier, 'image/png', null, true),
        imageType: constant(ImageType::class.'::'.$type),
        entityId: EVENT_ID,
    ));
}

$video = DOSSIER.'/concert-entete-hd-90-nb.mp4';
if (is_readable($video)) {
    app(UploadEventCoverVideoHandler::class)->handle(new UploadEventCoverVideoDTO(
        eventId: EVENT_ID,
        accountId: ACCOUNT_ID,
        video: new UploadedFile($video, 'concert-des-generations.mp4', 'video/mp4', null, true),
    ));
}

echo 'Événement 14 | thème '.DB::table('event_settings')->where('event_id', EVENT_ID)->value('homepage_theme_settings')."\n";
echo 'Images : '.DB::table('images')->where('entity_id', EVENT_ID)->where('entity_type', 'like', '%EventDomainObject')->whereNull('deleted_at')
    ->pluck('type')->sort()->implode(', ')."\n";
