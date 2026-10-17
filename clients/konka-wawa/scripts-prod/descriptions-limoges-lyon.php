<?php
// Konka Wawa (compte 6) : Limoges n'est pas la finale de la tournée (d'autres dates arrivent).
// Remplace uniquement les phrases concernées dans les descriptions actuelles de Limoges (11) et Lyon (12),
// sans toucher à la mise en forme retouchée ni aux autres champs (repris tels quels). Relançable.

use Carbon\Carbon;
use HiEvents\DataTransferObjects\AddressDTO;
use HiEvents\DomainObjects\Enums\EventCategory;
use HiEvents\Services\Application\Handlers\Event\DTO\UpdateEventDTO;
use HiEvents\Services\Application\Handlers\Event\UpdateEventHandler;
use Illuminate\Support\Facades\DB;

const ORGANIZER_ID = 9;
const ACCOUNT_ID = 6;
const USER_ID = 10;

$remplacements = [
    11 => ['Limoges', [
        'Limoges, la grande finale.' => 'Limoges, cinquième étape de la tournée.',
        'De Marseille à Lyon, la tournée a fait danser toute la France. Elle se termine au Pavillon de Buxerolles, et pour la dernière nuit il fallait le plus grand tube : « Sabina », que tout le monde connaît par cœur. On finit en beauté, tous ensemble. 🎉'
            => 'Au lendemain de Lyon, Wawa pose ses valises au Pavillon de Buxerolles pour une nuit salegy en Limousin. Au programme, le tube que tout le monde connaît par cœur : « Sabina ». Et ce n\'est qu\'une étape : d\'autres dates arrivent très bientôt. 🎉',
    ]],
    12 => ['Lyon', [
        'Avant-dernière étape : Lyon ouvre le week-end final.' => 'Quatrième étape : Lyon lance un week-end double.',
        '« Ao zay » pour chauffer la salle : c\'est l\'avant-dernière occasion de vivre les 25 ans de scène de Wawa en live.'
            => '« Ao zay » pour chauffer la salle, avant de filer à Limoges dès le lendemain.',
    ]],
];

$nouvelles = [];
foreach ($remplacements as $eventId => [$ville, $phrases]) {
    $event = DB::table('events')->where('id', $eventId)->where('organizer_id', ORGANIZER_ID)->where('account_id', ACCOUNT_ID)->whereNull('deleted_at')->first();
    if (!$event || $event->title !== 'Wawa – 25 ans de scène · '.$ville) {
        throw new RuntimeException('Événement '.$eventId.' inattendu : rien n\'est modifié');
    }
    $texte = $event->description;
    foreach ($phrases as $ancienne => $nouvelle) {
        if (str_contains($texte, $nouvelle)) {
            continue;
        }
        if (!str_contains($texte, $ancienne)) {
            throw new RuntimeException($ville.' : phrase introuvable, le texte a changé : rien n\'est modifié');
        }
        $texte = str_replace($ancienne, $nouvelle, $texte);
    }
    $nouvelles[$eventId] = [$event, $texte];
}

auth()->setUser(\HiEvents\Models\User::findOrFail(USER_ID));

DB::transaction(function () use ($nouvelles) {
    foreach ($nouvelles as $eventId => [$event, $texte]) {
        $local = fn (?string $utc) => $utc ? Carbon::parse($utc, 'UTC')->setTimezone($event->timezone)->format('Y-m-d H:i:s') : null;
        app(UpdateEventHandler::class)->handle(new UpdateEventDTO(
            title: $event->title,
            category: EventCategory::tryFrom((string) $event->category),
            account_id: ACCOUNT_ID,
            id: $eventId,
            start_date: $local($event->start_date),
            end_date: $local($event->end_date),
            description: $texte,
            timezone: $event->timezone,
            currency: $event->currency,
            location: $event->location,
            location_details: $event->location_details ? AddressDTO::from(json_decode($event->location_details, true)) : null,
        ));
    }
});

foreach ($nouvelles as $eventId => [$avant]) {
    $apres = DB::table('events')->where('id', $eventId)->first();
    preg_match('/<strong>(.*?)<\/strong>/u', $apres->description, $titre);
    $inchange = $apres->start_date === $avant->start_date && $apres->end_date === $avant->end_date && json_decode((string) $apres->location_details, true) == json_decode((string) $avant->location_details, true);
    echo $eventId.' | '.($titre[1] ?? '?').' | dates et lieu inchangés : '.($inchange ? 'oui' : 'NON')."\n";
}
