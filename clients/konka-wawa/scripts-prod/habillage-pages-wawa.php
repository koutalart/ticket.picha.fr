<?php
// Konka Wawa (compte 6) : pages aux couleurs des affiches.
// Miniatures (image principale + image de partage) pour Marseille, Colmar, Toulouse, Lyon,
// et thème de page (fond sombre, couleur de la ville, police Outfit) sur les 5 dates.
// Mêmes traitements que l'interface ; seul le thème change dans les paramètres. Relançable.

use HiEvents\DomainObjects\Enums\ImageType;
use HiEvents\Services\Application\Handlers\EventSettings\DTO\PartialUpdateEventSettingsDTO;
use HiEvents\Services\Application\Handlers\EventSettings\PartialUpdateEventSettingsHandler;
use HiEvents\Services\Application\Handlers\Images\CreateImageHandler;
use HiEvents\Services\Application\Handlers\Images\DTO\CreateImageDTO;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

const ORGANIZER_ID = 9;
const ACCOUNT_ID = 6;
const USER_ID = 10;
const DOSSIER = '/tmp/wawa-pages';
const FOND = '#0e0c16';

$dates = [
    8 => ['Marseille', 'marseille', '#b47cff'],
    9 => ['Colmar', 'colmar', '#ff4f8b'],
    10 => ['Toulouse', 'toulouse', '#5ce36e'],
    12 => ['Lyon', 'lyon', '#ff5a2e'],
    11 => ['Limoges', null, '#6fd3ff'],
];

foreach ($dates as $eventId => [$ville, $slug]) {
    $event = DB::table('events')->where('id', $eventId)->where('organizer_id', ORGANIZER_ID)->where('account_id', ACCOUNT_ID)->whereNull('deleted_at')->first();
    if (!$event || $event->title !== 'Wawa – 25 ans de scène · '.$ville) {
        throw new RuntimeException('Événement '.$eventId.' inattendu : rien n\'est modifié');
    }
    if ($slug && !is_readable(DOSSIER."/wawa-$slug-miniature.jpg")) {
        throw new RuntimeException('Miniature manquante : '.$slug.' : rien n\'est modifié');
    }
}

auth()->setUser(\HiEvents\Models\User::findOrFail(USER_ID));

foreach ($dates as $eventId => [$ville, $slug, $accent]) {
    app(PartialUpdateEventSettingsHandler::class)->handle(new PartialUpdateEventSettingsDTO(
        account_id: ACCOUNT_ID,
        event_id: $eventId,
        settings: ['homepage_theme_settings' => [
            'mode' => 'dark',
            'accent' => $accent,
            'background' => FOND,
            'background_type' => 'COLOR',
            'font_family' => 'Outfit',
        ]],
    ));

    if ($slug) {
        $miniature = DOSSIER."/wawa-$slug-miniature.jpg";
        foreach ([ImageType::EVENT_COVER, ImageType::EVENT_SHARE_IMAGE] as $type) {
            app(CreateImageHandler::class)->handle(new CreateImageDTO(
                userId: USER_ID,
                accountId: ACCOUNT_ID,
                image: new UploadedFile($miniature, basename($miniature), 'image/jpeg', null, true),
                imageType: $type,
                entityId: $eventId,
            ));
        }
    }

    $theme = DB::table('event_settings')->where('event_id', $eventId)->value('homepage_theme_settings');
    $images = DB::table('images')->where('entity_id', $eventId)->where('entity_type', 'like', '%EventDomainObject')
        ->whereIn('type', ['EVENT_COVER', 'EVENT_SHARE_IMAGE', 'EVENT_COVER_VIDEO'])->whereNull('deleted_at')
        ->pluck('type')->sort()->implode(', ');
    echo $eventId.' | '.$ville.' | thème '.$theme.' | '.$images."\n";
}
