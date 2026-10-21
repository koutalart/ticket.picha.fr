<?php
// Mayotte la 1ère (compte 5) : thème KULTUR'M de l'en-tête 40 ans sur l'événement 14 (bleu marine, or, Montserrat),
// et retour de la page organisateur 8 à son thème d'origine (« Midnight »), en attendant la décision de Jo. Relançable.

use HiEvents\Services\Application\Handlers\EventSettings\DTO\PartialUpdateEventSettingsDTO;
use HiEvents\Services\Application\Handlers\EventSettings\PartialUpdateEventSettingsHandler;
use HiEvents\Services\Application\Handlers\Organizer\DTO\PartialUpdateOrganizerSettingsDTO;
use HiEvents\Services\Application\Handlers\Organizer\Settings\PartialUpdateOrganizerSettingsHandler;
use Illuminate\Support\Facades\DB;

$event = DB::table('events')->where('id', 14)->where('account_id', 5)->where('organizer_id', 8)->whereNull('deleted_at')->first();
if (!$event || $event->title !== 'Le Concert des Générations') {
    throw new RuntimeException('Événement inattendu : rien n\'est modifié');
}

auth()->setUser(\HiEvents\Models\User::findOrFail(8));

app(PartialUpdateEventSettingsHandler::class)->handle(new PartialUpdateEventSettingsDTO(
    account_id: 5,
    event_id: 14,
    settings: ['homepage_theme_settings' => [
        'mode' => 'dark',
        'accent' => '#b8963e',
        'background' => '#0f1830',
        'background_type' => 'COLOR',
        'font_family' => 'Montserrat',
    ]],
));

app(PartialUpdateOrganizerSettingsHandler::class)->handle(PartialUpdateOrganizerSettingsDTO::from([
    'organizer_id' => 8,
    'account_id' => '5',
    'homepage_theme_settings' => [
        'name' => 'Midnight',
        'homepage_primary_color' => '#ffffffff',
        'homepage_secondary_color' => '#b3b3b3ff',
        'homepage_background_color' => '#737373ff',
        'homepage_primary_text_color' => '#ffffffff',
        'homepage_secondary_text_color' => '#ffffff',
        'homepage_content_background_color' => '#0f172a9c',
    ],
]));

echo 'Événement 14 | '.DB::table('event_settings')->where('event_id', 14)->value('homepage_theme_settings')."\n";
echo 'Organisateur 8 | '.DB::table('organizer_settings')->where('organizer_id', 8)->value('homepage_theme_settings')."\n";
