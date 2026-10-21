<?php
// Mayotte la 1ère (compte 5, organisateur 8) : police franceTV Brown (licence de Jo) sur la page organisateur
// et sur l'événement 14 (PR #174). Fichiers déposés comme un organisateur, règles du formulaire appliquées.
// Relançable : une graisse déjà déposée n'est pas renvoyée.

use HiEvents\DomainObjects\Enums\OrganizerFontWeight;
use HiEvents\Http\Request\Organizer\Font\UploadOrganizerFontRequest;
use HiEvents\Services\Application\Handlers\EventSettings\DTO\PartialUpdateEventSettingsDTO;
use HiEvents\Services\Application\Handlers\EventSettings\PartialUpdateEventSettingsHandler;
use HiEvents\Services\Application\Handlers\Organizer\DTO\PartialUpdateOrganizerSettingsDTO;
use HiEvents\Services\Application\Handlers\Organizer\Font\DTO\UploadOrganizerFontDTO;
use HiEvents\Services\Application\Handlers\Organizer\Font\UploadOrganizerFontHandler;
use HiEvents\Services\Application\Handlers\Organizer\Settings\PartialUpdateOrganizerSettingsHandler;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

const ACCOUNT_ID = 5;
const ORGANIZER_ID = 8;
const EVENT_ID = 14;
const USER_ID = 8;
const DOSSIER = '/tmp/police';
const FAMILLE = 'franceTV Brown';

$graisses = [300 => 'Light', 400 => 'Regular', 700 => 'Bold', 900 => 'Black'];

$organizer = DB::table('organizers')->where('id', ORGANIZER_ID)->where('account_id', ACCOUNT_ID)->whereNull('deleted_at')->first();
if (!$organizer || $organizer->name !== 'Mayotte La 1ère') {
    throw new RuntimeException('Organisateur inattendu : rien n\'est modifié');
}
foreach ($graisses as $nom) {
    if (!is_readable(DOSSIER."/FranceTVBrownTT-$nom.ttf")) {
        throw new RuntimeException("Fichier manquant : FranceTVBrownTT-$nom.ttf : rien n'est modifié");
    }
}

auth()->setUser(\HiEvents\Models\User::findOrFail(USER_ID));

$regles = (new UploadOrganizerFontRequest())->rules();
foreach ($graisses as $poids => $nom) {
    if (DB::table('organizer_fonts')->where('organizer_id', ORGANIZER_ID)->where('weight', $poids)->exists()) {
        continue;
    }
    $fichier = new UploadedFile(DOSSIER."/FranceTVBrownTT-$nom.ttf", "FranceTVBrownTT-$nom.ttf", 'font/ttf', null, true);
    Validator::make(['font_file' => $fichier, 'weight' => $poids, 'family_name' => FAMILLE], $regles)->validate();
    app(UploadOrganizerFontHandler::class)->handle(new UploadOrganizerFontDTO(
        organizerId: ORGANIZER_ID,
        accountId: ACCOUNT_ID,
        familyName: FAMILLE,
        weight: OrganizerFontWeight::from($poids),
        file: $fichier,
    ));
}

$themeOrga = json_decode(DB::table('organizer_settings')->where('organizer_id', ORGANIZER_ID)->value('homepage_theme_settings') ?? '{}', true) ?: [];
app(PartialUpdateOrganizerSettingsHandler::class)->handle(PartialUpdateOrganizerSettingsDTO::from([
    'organizer_id' => ORGANIZER_ID,
    'account_id' => (string) ACCOUNT_ID,
    'homepage_theme_settings' => array_merge($themeOrga, ['font_family' => 'organizer_font']),
]));

$themeEvent = json_decode(DB::table('event_settings')->where('event_id', EVENT_ID)->value('homepage_theme_settings') ?? '{}', true) ?: [];
app(PartialUpdateEventSettingsHandler::class)->handle(new PartialUpdateEventSettingsDTO(
    account_id: ACCOUNT_ID,
    event_id: EVENT_ID,
    settings: ['homepage_theme_settings' => array_merge($themeEvent, ['font_family' => 'organizer_font'])],
));

echo 'Polices | '.DB::table('organizer_fonts')->where('organizer_id', ORGANIZER_ID)->orderBy('weight')->get(['family_name', 'weight'])
    ->map(fn ($f) => $f->family_name.' '.$f->weight)->implode(', ')."\n";
echo 'Organisateur 8 | '.json_decode(DB::table('organizer_settings')->where('organizer_id', ORGANIZER_ID)->value('homepage_theme_settings'), true)['font_family']."\n";
echo 'Événement 14 | '.json_decode(DB::table('event_settings')->where('event_id', EVENT_ID)->value('homepage_theme_settings'), true)['font_family']."\n";
