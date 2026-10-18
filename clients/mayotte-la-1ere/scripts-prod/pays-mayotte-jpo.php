<?php
// Mayotte la 1ère (compte 5, organisateur 8) : pays « Mayotte » (YT) sur le lieu de la Journée Portes Ouvertes,
// pour que le kiosque applique l'indicatif +262 sans réglage. Seul le pays change. Relançable.

use HiEvents\Helper\PhoneCallingCode;
use HiEvents\Services\Application\Handlers\EventSettings\DTO\PartialUpdateEventSettingsDTO;
use HiEvents\Services\Application\Handlers\EventSettings\PartialUpdateEventSettingsHandler;
use Illuminate\Support\Facades\DB;

const ACCOUNT_ID = 5;
const ORGANIZER_ID = 8;
const EVENT_ID = 6;
const USER_ID = 8;

$event = DB::table('events')->where('id', EVENT_ID)->where('organizer_id', ORGANIZER_ID)->where('account_id', ACCOUNT_ID)->whereNull('deleted_at')->first();
if (!$event || !str_starts_with($event->title, 'Journée Portes Ouvertes')) {
    throw new RuntimeException('Événement inattendu : rien n\'est modifié');
}

$lieu = json_decode(DB::table('event_settings')->where('event_id', EVENT_ID)->value('location_details') ?? '{}', true) ?: [];
$lieu['country'] = 'YT';

auth()->setUser(\HiEvents\Models\User::findOrFail(USER_ID));

app(PartialUpdateEventSettingsHandler::class)->handle(new PartialUpdateEventSettingsDTO(
    account_id: ACCOUNT_ID,
    event_id: EVENT_ID,
    settings: ['location_details' => $lieu],
));

$pays = PhoneCallingCode::iso2FromLocationDetails(DB::table('event_settings')->where('event_id', EVENT_ID)->value('location_details'));
echo 'JPO | pays : '.$pays.' | indicatif kiosque : +'.PhoneCallingCode::fromIso2($pays)."\n";
