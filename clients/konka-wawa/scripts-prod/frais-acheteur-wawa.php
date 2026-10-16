<?php
// Konka Wawa (compte 6) : frais de plateforme payés par l'acheteur sur les 5 dates.
// Même traitement que l'écran des paramètres de l'événement ; seul ce réglage change. Relançable.

use HiEvents\Services\Application\Handlers\EventSettings\DTO\PartialUpdateEventSettingsDTO;
use HiEvents\Services\Application\Handlers\EventSettings\PartialUpdateEventSettingsHandler;
use Illuminate\Support\Facades\DB;

const ORGANIZER_ID = 9;
const ACCOUNT_ID = 6;
const USER_ID = 10;

$dates = [8 => 'Marseille', 9 => 'Colmar', 10 => 'Toulouse', 11 => 'Limoges', 12 => 'Lyon'];

foreach ($dates as $eventId => $ville) {
    $event = DB::table('events')->where('id', $eventId)->where('organizer_id', ORGANIZER_ID)->where('account_id', ACCOUNT_ID)->whereNull('deleted_at')->first();
    if (!$event || $event->title !== 'Wawa – 25 ans de scène · '.$ville) {
        throw new RuntimeException('Événement '.$eventId.' inattendu : rien n\'est modifié');
    }
}

auth()->setUser(\HiEvents\Models\User::findOrFail(USER_ID));

DB::transaction(function () use ($dates) {
    foreach ($dates as $eventId => $ville) {
        app(PartialUpdateEventSettingsHandler::class)->handle(new PartialUpdateEventSettingsDTO(
            account_id: ACCOUNT_ID,
            event_id: $eventId,
            settings: ['pass_platform_fee_to_buyer' => true],
        ));
    }
});

foreach ($dates as $eventId => $ville) {
    $actif = DB::table('event_settings')->where('event_id', $eventId)->value('pass_platform_fee_to_buyer');
    echo $eventId.' | '.$ville.' | frais payés par l\'acheteur : '.($actif ? 'oui' : 'NON')."\n";
}
