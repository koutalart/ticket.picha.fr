<?php
// Mayotte la 1ère (compte 5) : charte de la chaîne, demandée par Jo le 22/10.
// Fond jaune #f9b617, accent jaune, police franceTV Brown, sur la page organisateur 8 et l'événement 14 ;
// bannière jaune à cercles de la chaîne sur la page organisateur. Le logo ne change pas. Relançable.

use HiEvents\DomainObjects\Enums\ImageType;
use HiEvents\Services\Application\Handlers\EventSettings\DTO\PartialUpdateEventSettingsDTO;
use HiEvents\Services\Application\Handlers\EventSettings\PartialUpdateEventSettingsHandler;
use HiEvents\Services\Application\Handlers\Images\CreateImageHandler;
use HiEvents\Services\Application\Handlers\Images\DTO\CreateImageDTO;
use HiEvents\Services\Application\Handlers\Organizer\DTO\PartialUpdateOrganizerSettingsDTO;
use HiEvents\Services\Application\Handlers\Organizer\Settings\PartialUpdateOrganizerSettingsHandler;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

const BANNIERE = '/tmp/banniere-mayotte-jaune.jpg';
$event = DB::table('events')->where('id', 14)->where('account_id', 5)->where('organizer_id', 8)->whereNull('deleted_at')->first();
if (!$event || $event->title !== 'Le Concert des Générations' || !is_readable(BANNIERE)) {
    throw new RuntimeException('Événement ou bannière inattendus : rien n\'est modifié');
}
$jaune = ['mode' => 'light', 'accent' => '#f9b617', 'background' => '#f9b617', 'background_type' => 'COLOR', 'font_family' => 'organizer_font'];

auth()->setUser(\HiEvents\Models\User::findOrFail(8));

app(PartialUpdateOrganizerSettingsHandler::class)->handle(PartialUpdateOrganizerSettingsDTO::from([
    'organizer_id' => 8, 'account_id' => '5', 'homepage_theme_settings' => $jaune,
]));

$themeEvent = json_decode(DB::table('event_settings')->where('event_id', 14)->value('homepage_theme_settings') ?? '{}', true) ?: [];
app(PartialUpdateEventSettingsHandler::class)->handle(new PartialUpdateEventSettingsDTO(
    account_id: 5, event_id: 14, settings: ['homepage_theme_settings' => array_merge($themeEvent, $jaune)],
));

app(CreateImageHandler::class)->handle(new CreateImageDTO(
    userId: 8, accountId: 5,
    image: new UploadedFile(BANNIERE, 'banniere-mayotte-la-1ere.jpg', 'image/jpeg', null, true),
    imageType: ImageType::ORGANIZER_COVER, entityId: 8,
));

echo 'Organisateur 8 | '.DB::table('organizer_settings')->where('organizer_id', 8)->value('homepage_theme_settings')."\n";
echo 'Événement 14 | '.DB::table('event_settings')->where('event_id', 14)->value('homepage_theme_settings')."\n";
