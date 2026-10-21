<?php
// Organisateur 8 : retire font_family du thème « Midnight » (format ancien), qui faussait l'affichage de la page organisateur.
use HiEvents\Services\Application\Handlers\Organizer\DTO\PartialUpdateOrganizerSettingsDTO;
use HiEvents\Services\Application\Handlers\Organizer\Settings\PartialUpdateOrganizerSettingsHandler;
use Illuminate\Support\Facades\DB;

$theme = json_decode(DB::table('organizer_settings')->where('organizer_id', 8)->value('homepage_theme_settings') ?? '{}', true) ?: [];
if (($theme['name'] ?? null) !== 'Midnight') {
    throw new RuntimeException('Thème inattendu : rien n\'est modifié');
}
unset($theme['font_family']);
auth()->setUser(\HiEvents\Models\User::findOrFail(8));
app(PartialUpdateOrganizerSettingsHandler::class)->handle(PartialUpdateOrganizerSettingsDTO::from([
    'organizer_id' => 8, 'account_id' => '5', 'homepage_theme_settings' => $theme,
]));
echo 'Organisateur 8 | '.DB::table('organizer_settings')->where('organizer_id', 8)->value('homepage_theme_settings')."\n";
