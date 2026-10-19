<?php
// Salon Mayotte Recrute (événement 13) : coordonnées demandées par participant (inscriptions gratuites :
// avec la collecte « par commande », la page n'affiche ni nom ni e-mail et la commande est refusée). Relançable.

use HiEvents\Services\Application\Handlers\EventSettings\DTO\PartialUpdateEventSettingsDTO;
use HiEvents\Services\Application\Handlers\EventSettings\PartialUpdateEventSettingsHandler;
use Illuminate\Support\Facades\DB;

if (DB::table('events')->where('id', 13)->where('account_id', 7)->value('title') !== 'Salon Mayotte Recrute 2026') {
    throw new RuntimeException('Événement inattendu : rien n\'est modifié');
}
auth()->setUser(\HiEvents\Models\User::findOrFail(11));
app(PartialUpdateEventSettingsHandler::class)->handle(new PartialUpdateEventSettingsDTO(
    account_id: 7,
    event_id: 13,
    settings: ['attendee_details_collection_method' => 'PER_TICKET', 'require_attendee_details' => true],
));
$s = DB::table('event_settings')->where('event_id', 13)->first(['attendee_details_collection_method', 'require_attendee_details']);
echo 'Collecte : '.$s->attendee_details_collection_method.' | coordonnées obligatoires : '.($s->require_attendee_details ? 'oui' : 'non')."\n";
