<?php
// Concert des Générations (compte 5, événement 14), validé par Jo le 22/10 (PR #172) :
// confirmation de venue activée, et rappel e-mail du 12/11 remplacé par une version avec le lien personnel.
// Relançable : l'ancien rappel n'est annulé que s'il est encore programmé.

use HiEvents\DomainObjects\Enums\MessageTypeEnum;
use HiEvents\Http\Request\EventSettings\UpdateEventSettingsRequest;
use HiEvents\Services\Application\Handlers\EventSettings\DTO\PartialUpdateEventSettingsDTO;
use HiEvents\Services\Application\Handlers\EventSettings\PartialUpdateEventSettingsHandler;
use HiEvents\Services\Application\Handlers\Message\CancelMessageHandler;
use HiEvents\Services\Application\Handlers\Message\DTO\SendMessageDTO;
use HiEvents\Services\Application\Handlers\Message\SendMessageHandler;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

const ACCOUNT_ID = 5;
const EVENT_ID = 14;
const USER_ID = 8;
const OBJET = 'Le Concert des Générations, ce samedi 14 novembre';

$event = DB::table('events')->where('id', EVENT_ID)->where('account_id', ACCOUNT_ID)->where('organizer_id', 8)->whereNull('deleted_at')->first();
if (!$event || $event->title !== 'Le Concert des Générations') {
    throw new RuntimeException('Événement inattendu : rien n\'est modifié');
}
$reglage = ['allow_attendee_attendance_response' => true];
Validator::make($reglage, ['allow_attendee_attendance_response' => (new UpdateEventSettingsRequest())->rules()['allow_attendee_attendance_response']])->validate();

auth()->setUser(\HiEvents\Models\User::findOrFail(USER_ID));

app(PartialUpdateEventSettingsHandler::class)->handle(new PartialUpdateEventSettingsDTO(
    account_id: ACCOUNT_ID,
    event_id: EVENT_ID,
    settings: $reglage,
));

$message = '<p>Madame, Monsieur,</p>'
    .'<p>Nous aurons le plaisir de vous accueillir ce samedi 14 novembre, dans les locaux de Mayotte La 1ère à Mamoudzou, pour le Concert des Générations.</p>'
    .'<p>L\'accueil débutera à 18h30, et le concert à 20h15. Une pièce d\'identité au nom inscrit sur votre billet vous sera demandée à l\'entrée.</p>'
    .'<p>Merci de nous confirmer votre venue, ou de libérer votre place si vous ne pouvez plus être des nôtres : <a href="{{ attendee.attendance_url }}">Confirmer ou annuler ma venue</a></p>'
    .'<p>Au plaisir de vous retrouver,</p>'
    .'<p>L\'équipe de Mayotte La 1ère et KULTUR\'M</p>';

$avecLien = DB::table('messages')->where('event_id', EVENT_ID)->where('subject', OBJET)->where('status', 'SCHEDULED')
    ->where('message', 'like', '%attendance_url%')->whereNull('deleted_at')->exists();
if (!$avecLien) {
    foreach (DB::table('messages')->where('event_id', EVENT_ID)->where('subject', OBJET)->where('status', 'SCHEDULED')->whereNull('deleted_at')->pluck('id') as $id) {
        app(CancelMessageHandler::class)->handle($id, EVENT_ID);
    }
    app(SendMessageHandler::class)->handle(new SendMessageDTO(
        account_id: ACCOUNT_ID,
        event_id: EVENT_ID,
        subject: OBJET,
        message: $message,
        type: MessageTypeEnum::ALL_ATTENDEES,
        is_test: false,
        send_copy_to_current_user: false,
        sent_by_user_id: USER_ID,
        scheduled_at: '2026-11-12 10:00:00',
    ));
}

echo 'Confirmation de venue | '.(DB::table('event_settings')->where('event_id', EVENT_ID)->value('allow_attendee_attendance_response') ? 'activée' : 'NON')."\n";
foreach (DB::table('messages')->where('event_id', EVENT_ID)->whereNull('deleted_at')->orderBy('id')->get(['id', 'status', 'scheduled_at', 'message']) as $m) {
    echo 'Message '.$m->id.' | '.$m->status.' | '.$m->scheduled_at.' UTC | lien '.(str_contains($m->message, 'attendance_url') ? 'oui' : 'non')."\n";
}
