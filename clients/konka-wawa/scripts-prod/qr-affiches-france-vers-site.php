<?php
// Konka Wawa : les QR codes des affiches de la tournée France (dates en brouillon, reportées en 2027)
// menaient à « Événement non disponible ». Ils mènent maintenant à la partie Tournée du site. Relançable.

use HiEvents\DomainObjects\Enums\QrLinkDestinationType;
use HiEvents\DomainObjects\Status\QrLinkStatus;
use HiEvents\Services\Application\Handlers\QrLink\DTO\UpsertQrLinkDTO;
use HiEvents\Services\Application\Handlers\QrLink\UpdateQrLinkHandler;
use Illuminate\Support\Facades\DB;

const ACCOUNT_ID = 6;
const ORGANIZER_ID = 9;
const USER_ID = 10;

$affiches = ['Affiche Marseille', 'Affiche Colmar', 'Affiche Toulouse', 'Affiche Lyon', 'Affiche Limoges'];
auth()->setUser(\HiEvents\Models\User::findOrFail(USER_ID));

foreach ($affiches as $nom) {
    $l = DB::table('qr_links')->where('organizer_id', ORGANIZER_ID)->where('name', $nom)->whereNull('deleted_at')->first();
    if (!$l) {
        throw new RuntimeException('Lien introuvable : '.$nom);
    }
    app(UpdateQrLinkHandler::class)->handle($l->id, new UpsertQrLinkDTO(
        accountId: ACCOUNT_ID,
        organizerId: ORGANIZER_ID,
        name: $nom,
        destinationType: QrLinkDestinationType::URL,
        status: QrLinkStatus::ACTIVE,
        destinationUrl: 'https://wawasalegy.com/?qr='.$l->short_id.'&qrc=1#tournee',
    ));
    $n = DB::table('qr_links')->where('id', $l->id)->first();
    echo $nom.' | /q/'.$n->short_id.' | '.$n->destination_type.' -> '.$n->destination_url."\n";
}
