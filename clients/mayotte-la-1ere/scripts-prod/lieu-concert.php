<?php
// Concert des Générations (compte 5, événement 14) : lieu « Mayotte La 1ère, 97600 Mamoudzou ». Relançable.

use HiEvents\Services\Application\Handlers\EventSettings\DTO\PartialUpdateEventSettingsDTO;
use HiEvents\Services\Application\Handlers\EventSettings\PartialUpdateEventSettingsHandler;
use Illuminate\Support\Facades\DB;

$event = DB::table('events')->where('id', 14)->where('account_id', 5)->where('organizer_id', 8)->whereNull('deleted_at')->first();
if (!$event || $event->title !== 'Le Concert des Générations') {
    throw new RuntimeException('Événement inattendu : rien n\'est modifié');
}
$lieu = json_decode(DB::table('event_settings')->where('event_id', 14)->value('location_details') ?? '{}', true) ?: [];
$lieu = array_merge($lieu, ['venue_name' => 'Mayotte La 1ère', 'city' => 'Mamoudzou', 'zip_or_postal_code' => '97600', 'country' => 'YT']);

auth()->setUser(\HiEvents\Models\User::findOrFail(8));
app(PartialUpdateEventSettingsHandler::class)->handle(new PartialUpdateEventSettingsDTO(
    account_id: 5,
    event_id: 14,
    settings: ['location_details' => $lieu],
));

echo 'Événement 14 | lieu '.DB::table('event_settings')->where('event_id', 14)->value('location_details')."\n";
