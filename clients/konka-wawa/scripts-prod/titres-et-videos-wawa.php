<?php
// Konka Wawa (compte 6) : titres sans « – » ni « · » (« Wawa, 25 ans de scène à Marseille »)
// et vidéos de couverture dont la ligne des dates n'a plus de « · ».
// Les autres champs des événements sont repris tels quels. Relançable.

use Carbon\Carbon;
use HiEvents\DataTransferObjects\AddressDTO;
use HiEvents\DomainObjects\Enums\EventCategory;
use HiEvents\Services\Application\Handlers\Event\CoverVideo\DTO\UploadEventCoverVideoDTO;
use HiEvents\Services\Application\Handlers\Event\CoverVideo\UploadEventCoverVideoHandler;
use HiEvents\Services\Application\Handlers\Event\DTO\UpdateEventDTO;
use HiEvents\Services\Application\Handlers\Event\UpdateEventHandler;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

const ORGANIZER_ID = 9;
const ACCOUNT_ID = 6;
const USER_ID = 10;
const DOSSIER = '/tmp/wawa-titres';

$dates = [8 => 'Marseille', 9 => 'Colmar', 10 => 'Toulouse', 12 => 'Lyon', 11 => 'Limoges'];
$ancien = fn (string $ville) => 'Wawa – 25 ans de scène · '.$ville;
$nouveau = fn (string $ville) => 'Wawa, 25 ans de scène à '.$ville;

$events = [];
foreach ($dates as $eventId => $ville) {
    $event = DB::table('events')->where('id', $eventId)->where('organizer_id', ORGANIZER_ID)->where('account_id', ACCOUNT_ID)->whereNull('deleted_at')->first();
    if (!$event || !in_array($event->title, [$ancien($ville), $nouveau($ville)], true)) {
        throw new RuntimeException('Événement '.$eventId.' inattendu : rien n\'est modifié');
    }
    $video = DOSSIER.'/wawa-'.strtolower($ville).'-couverture.mp4';
    if (!is_readable($video) || filesize($video) > 15 * 1024 * 1024) {
        throw new RuntimeException('Vidéo manquante ou trop lourde : '.$ville.' : rien n\'est modifié');
    }
    $events[$eventId] = $event;
}

auth()->setUser(\HiEvents\Models\User::findOrFail(USER_ID));

DB::transaction(function () use ($events, $dates, $nouveau) {
    foreach ($events as $eventId => $event) {
        $local = fn (?string $utc) => $utc ? Carbon::parse($utc, 'UTC')->setTimezone($event->timezone)->format('Y-m-d H:i:s') : null;
        app(UpdateEventHandler::class)->handle(new UpdateEventDTO(
            title: $nouveau($dates[$eventId]),
            category: EventCategory::tryFrom((string) $event->category),
            account_id: ACCOUNT_ID,
            id: $eventId,
            start_date: $local($event->start_date),
            end_date: $local($event->end_date),
            description: $event->description,
            timezone: $event->timezone,
            currency: $event->currency,
            location: $event->location,
            location_details: $event->location_details ? AddressDTO::from(json_decode($event->location_details, true)) : null,
        ));
    }
});

foreach ($dates as $eventId => $ville) {
    $fichier = DOSSIER.'/wawa-'.strtolower($ville).'-couverture.mp4';
    app(UploadEventCoverVideoHandler::class)->handle(new UploadEventCoverVideoDTO(
        eventId: $eventId,
        accountId: ACCOUNT_ID,
        video: new UploadedFile($fichier, basename($fichier), 'video/mp4', null, true),
    ));

    $avant = $events[$eventId];
    $apres = DB::table('events')->where('id', $eventId)->first();
    $inchange = $apres->start_date === $avant->start_date && $apres->end_date === $avant->end_date
        && $apres->description === $avant->description
        && json_decode((string) $apres->location_details, true) == json_decode((string) $avant->location_details, true);
    $videos = DB::table('images')->where('entity_id', $eventId)->where('type', 'EVENT_COVER_VIDEO')->whereNull('deleted_at')->count();
    echo $eventId.' | '.$apres->title.' | reste inchangé : '.($inchange ? 'oui' : 'NON').' | vidéos actives : '.$videos."\n";
}
