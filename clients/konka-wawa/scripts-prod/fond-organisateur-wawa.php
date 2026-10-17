<?php
// Konka Wawa (organisateur 9) : couleur de fond du thème de la page organisateur en sombre (#0e0c16)
// au lieu du lavande clair (#f5f3ff) qui s'affichait en flash pendant le chargement.
// Seule la couleur de fond change ; mode, accent, police et type de fond sont repris tels quels. Relançable.

use HiEvents\Services\Application\Handlers\Organizer\DTO\PartialUpdateOrganizerSettingsDTO;
use HiEvents\Services\Application\Handlers\Organizer\Settings\PartialUpdateOrganizerSettingsHandler;
use Illuminate\Support\Facades\DB;

const ORGANIZER_ID = 9;
const ACCOUNT_ID = 6;
const USER_ID = 10;
const FOND = '#0e0c16';

$organizer = DB::table('organizers')->where('id', ORGANIZER_ID)->where('account_id', ACCOUNT_ID)->whereNull('deleted_at')->first();
if (!$organizer || $organizer->name !== 'Konka Wawa') {
    throw new RuntimeException('Organisateur inattendu : rien n\'est modifié');
}
$theme = json_decode((string) DB::table('organizer_settings')->where('organizer_id', ORGANIZER_ID)->value('homepage_theme_settings'), true) ?: [];
$avant = $theme;
$theme['background'] = FOND;

auth()->setUser(\HiEvents\Models\User::findOrFail(USER_ID));

app(PartialUpdateOrganizerSettingsHandler::class)->handle(PartialUpdateOrganizerSettingsDTO::from([
    'organizer_id' => ORGANIZER_ID,
    'account_id' => (string) ACCOUNT_ID,
    'homepage_theme_settings' => $theme,
]));

$apres = json_decode((string) DB::table('organizer_settings')->where('organizer_id', ORGANIZER_ID)->value('homepage_theme_settings'), true);
unset($avant['background'], $apres['background']);
echo 'Fond : '.json_decode((string) DB::table('organizer_settings')->where('organizer_id', ORGANIZER_ID)->value('homepage_theme_settings'), true)['background']
    .' | reste du thème inchangé : '.($avant == $apres ? 'oui' : 'NON')."\n";
