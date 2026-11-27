<?php
// Événement 32 : les rappels par défaut 39 (J-7) et 40 (veille) prennent les textes validés par Jo le 27/11 ; le 41 (jour J par défaut, 5h00)
// est annulé, le 42 (jour J validé, 6h30) reste. Rejouable.

use HiEvents\Services\Application\Handlers\Sms\CancelSmsReminderHandler;
use HiEvents\Services\Application\Handlers\Sms\UpdateSmsReminderHandler;
use HiEvents\Services\Domain\Sms\DTO\SmsReminderRequestDTO;
use Illuminate\Support\Facades\DB;

$textes = [
    39 => ['2026-11-28 18:00', 'rappel J-7', "Plus qu'une semaine avant la Nuit de l'Orientation 🔭 Samedi 5 décembre, de 8h00 à 12h30 au Technopôle de Dembéni : démonstrations métiers, conférences, ateliers et job dating.\n\nAnicka"],
    40 => ['2026-12-04 18:00', 'rappel la veille', "C'est demain {prénom} ! La Nuit de l'Orientation vous attend dès 8h00 au Technopôle de Dembéni. Gardez votre billet (QR code) sur votre téléphone pour l'entrée.\n\nAnicka"],
];
foreach ([39, 40, 41, 42] as $id) {
    if (!DB::table('sms_campaigns')->where('id', $id)->where('event_id', 32)->where('organizer_id', 22)->whereNull('deleted_at')->exists()) {
        throw new RuntimeException("Campagne $id inattendue : arrêt");
    }
}
auth()->setUser(\HiEvents\Models\User::findOrFail(22));
foreach ($textes as $id => [$quand, $nom, $message]) {
    app(UpdateSmsReminderHandler::class)->handle($id, new SmsReminderRequestDTO(
        organizerId: 22, eventId: 32, message: $message, sendAt: $quand, name: $nom, userId: 22,
    ));
}
if (DB::table('sms_campaigns')->where('id', 41)->value('status') === 'SCHEDULED') {
    app(CancelSmsReminderHandler::class)->handle(22, 41);
}
foreach (DB::table('sms_campaigns')->where('event_id', 32)->whereNull('deleted_at')->orderBy('id')->get() as $c) {
    echo "{$c->id} | {$c->name} | {$c->status} | {$c->scheduled_at} UTC | ".str_replace("\n", ' / ', $c->message)."\n";
}
