<?php
// Événement 32 (La Nuit de l'Orientation, organisateur 22) : couverture + image de partage tirées de l'affiche, thème de l'affiche
// sur la base de la Marche des Amis (clair, fond miroir de la couverture, en-tête en carte), inscription intégrée pas à pas. Rejouable.

use HiEvents\DomainObjects\Enums\ImageType;
use HiEvents\Services\Application\Handlers\EventSettings\DTO\PartialUpdateEventSettingsDTO;
use HiEvents\Services\Application\Handlers\EventSettings\PartialUpdateEventSettingsHandler;
use HiEvents\Services\Application\Handlers\Images\CreateImageHandler;
use HiEvents\Services\Application\Handlers\Images\DTO\CreateImageDTO;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

const ACCOUNT_ID = 18;
const EVENT_ID = 32;
const USER_ID = 22;
const COUVERTURE = '/tmp/nuit-orientation-2026-couverture.jpg';
const PARTAGE = '/tmp/nuit-orientation-2026-partage.jpg';

$e = DB::table('events')->where('id', EVENT_ID)->where('account_id', ACCOUNT_ID)->where('organizer_id', 22)->whereNull('deleted_at')->first();
if (!$e || $e->title !== 'La Nuit de l\'Orientation') {
    throw new RuntimeException('Événement inattendu : rien n\'est modifié');
}
auth()->setUser(\HiEvents\Models\User::findOrFail(USER_ID));

$dejaLa = DB::table('images')->where('entity_id', EVENT_ID)->where('entity_type', 'like', '%EventDomainObject')->whereNull('deleted_at')->pluck('type')->all();
foreach ([[ImageType::EVENT_COVER, COUVERTURE], [ImageType::EVENT_SHARE_IMAGE, PARTAGE]] as [$type, $fichier]) {
    if (in_array($type->name, $dejaLa, true)) {
        continue;
    }
    $copie = sys_get_temp_dir().'/cci-'.$type->name.'-'.basename($fichier);
    copy($fichier, $copie);
    app(CreateImageHandler::class)->handle(new CreateImageDTO(
        userId: USER_ID,
        accountId: ACCOUNT_ID,
        image: new UploadedFile($copie, basename($fichier), 'image/jpeg', null, true),
        imageType: $type,
        entityId: EVENT_ID,
    ));
}

app(PartialUpdateEventSettingsHandler::class)->handle(new PartialUpdateEventSettingsDTO(
    account_id: ACCOUNT_ID,
    event_id: EVENT_ID,
    settings: [
        'embedded_registration_enabled' => true,
        'homepage_theme_settings' => [
            'mode' => 'light',
            'accent' => '#C8102E',
            'background' => '#ffffff',
            'font_family' => 'Outfit',
            'hero_layout' => 'CARD',
            'background_type' => 'MIRROR_COVER_IMAGE',
        ],
    ],
));

$s = DB::table('event_settings')->where('event_id', EVENT_ID)->first();
echo 'Inscription intégrée '.($s->embedded_registration_enabled ? 'oui' : 'non').' | thème '.$s->homepage_theme_settings."\n";
echo 'Images | '.DB::table('images')->where('entity_id', EVENT_ID)->where('entity_type', 'like', '%EventDomainObject')->whereNull('deleted_at')->pluck('type')->implode(', ')."\n";
