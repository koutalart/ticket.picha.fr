<?php
// Konka Wawa (organisateur 9) : liens de suivi par canal, pour savoir d'où viennent les visiteurs et les acheteurs.
// - un lien par réseau / canal vers la page Tournée (wawasalegy.com), avec ?qr=<code>&qrc=1 pour que la commande
//   soit rattachée au lien (30 jours) ;
// - un lien par ville pour les QR codes imprimés sur les affiches, vers la page de l'événement.
// Même traitement que l'écran « QR codes ». Relançable : un lien qui existe déjà (même nom) n'est pas recréé.

use HiEvents\DomainObjects\Enums\QrLinkDestinationType;
use HiEvents\DomainObjects\Status\QrLinkStatus;
use HiEvents\Services\Application\Handlers\QrLink\CreateQrLinkHandler;
use HiEvents\Services\Application\Handlers\QrLink\DTO\UpsertQrLinkDTO;
use HiEvents\Services\Application\Handlers\QrLink\UpdateQrLinkHandler;
use Illuminate\Support\Facades\DB;

const ORGANIZER_ID = 9;
const ACCOUNT_ID = 6;
const USER_ID = 10;
const SITE = 'https://wawasalegy.com/';

$canaux = ['Instagram bio', 'Instagram stories et posts', 'Facebook', 'WhatsApp', 'TikTok', 'SMS'];
$affiches = [8 => 'Marseille', 9 => 'Colmar', 10 => 'Toulouse', 12 => 'Lyon', 11 => 'Limoges'];

$organizer = DB::table('organizers')->where('id', ORGANIZER_ID)->where('account_id', ACCOUNT_ID)->whereNull('deleted_at')->first();
if (!$organizer || $organizer->name !== 'Konka Wawa') {
    throw new RuntimeException('Organisateur inattendu : rien n\'est créé');
}
foreach ($affiches as $eventId => $ville) {
    if (!DB::table('events')->where('id', $eventId)->where('organizer_id', ORGANIZER_ID)->whereNull('deleted_at')->exists()) {
        throw new RuntimeException('Événement '.$eventId.' introuvable : rien n\'est créé');
    }
}

auth()->setUser(\HiEvents\Models\User::findOrFail(USER_ID));

$existe = fn (string $nom) => DB::table('qr_links')->where('organizer_id', ORGANIZER_ID)->where('name', $nom)->whereNull('deleted_at')->first();

foreach ($canaux as $canal) {
    if ($existe($canal)) {
        continue;
    }
    $dto = fn (string $url) => new UpsertQrLinkDTO(
        accountId: ACCOUNT_ID,
        organizerId: ORGANIZER_ID,
        name: $canal,
        destinationType: QrLinkDestinationType::URL,
        status: QrLinkStatus::ACTIVE,
        destinationUrl: $url,
    );
    $lien = app(CreateQrLinkHandler::class)->handle($dto(SITE));
    app(UpdateQrLinkHandler::class)->handle($lien->getId(), $dto(SITE.'?qr='.$lien->getShortId().'&qrc=1'));
}

foreach ($affiches as $eventId => $ville) {
    $nom = 'Affiche '.$ville;
    if ($existe($nom)) {
        continue;
    }
    app(CreateQrLinkHandler::class)->handle(new UpsertQrLinkDTO(
        accountId: ACCOUNT_ID,
        organizerId: ORGANIZER_ID,
        name: $nom,
        destinationType: QrLinkDestinationType::EVENT,
        status: QrLinkStatus::ACTIVE,
        eventId: $eventId,
    ));
}

foreach (DB::table('qr_links')->where('organizer_id', ORGANIZER_ID)->whereNull('deleted_at')->orderBy('id')->get() as $l) {
    echo str_pad($l->name, 28).' | https://wawasalegy.com/q/'.$l->short_id.' | '.$l->destination_type.($l->destination_url ? ' -> '.$l->destination_url : ' -> événement '.$l->event_id)."\n";
}
