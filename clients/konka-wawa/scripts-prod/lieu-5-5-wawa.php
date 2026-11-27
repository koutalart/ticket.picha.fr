<?php
// Konka Wawa (compte 6) : la soirée du 11/12 (événement 20) passe du Barakili au 5/5, à Mamoudzou.
// Titre, lieu affiché, nom du lien de suivi, et ville publique des 3 soirées (copiée de Marseille par erreur).
// Relançable.

use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\EventSettingsRepositoryInterface;
use HiEvents\Repository\Interfaces\QrLinkRepositoryInterface;
use Illuminate\Support\Facades\DB;

const ACCOUNT_ID = 6;
const ORGANIZER_ID = 9;
const EVENT_ID = 20;
const QR_ID = 639;

$villes = [20 => 'Mamoudzou', 21 => 'Bandrélé', 22 => 'Acoua'];

$event = DB::table('events')->where('id', EVENT_ID)->where('account_id', ACCOUNT_ID)->where('organizer_id', ORGANIZER_ID)->whereNull('deleted_at')->first();
if (!$event || !in_array($event->title, ['Wawa, 72 H à Mayotte : Barakili', 'Wawa, 72 H à Mayotte : 5/5'], true)) {
    throw new RuntimeException('Événement 20 inattendu : rien n\'est modifié');
}
$qr = DB::table('qr_links')->where('id', QR_ID)->where('organizer_id', ORGANIZER_ID)->whereNull('deleted_at')->first();
if (!$qr || !in_array($qr->name, ['Wawa 72H Barakili', 'Wawa 72H 5/5'], true)) {
    throw new RuntimeException('Lien 639 inattendu : rien n\'est modifié');
}
foreach ($villes as $id => $v) {
    if (!DB::table('events')->where('id', $id)->where('organizer_id', ORGANIZER_ID)->whereNull('deleted_at')->exists()) {
        throw new RuntimeException('Événement '.$id.' introuvable : rien n\'est modifié');
    }
}

DB::transaction(function () use ($villes) {
    app(EventRepositoryInterface::class)->updateByIdWhere(
        id: EVENT_ID,
        attributes: ['title' => 'Wawa, 72 H à Mayotte : 5/5'],
        where: ['account_id' => ACCOUNT_ID],
    );

    $lieu = json_decode((string) DB::table('event_settings')->where('event_id', EVENT_ID)->value('location_details'), true) ?: [];
    $lieu['venue_name'] = '5/5';
    $lieu['city'] = 'Mamoudzou';
    app(EventSettingsRepositoryInterface::class)->updateWhere(
        attributes: ['location_details' => json_encode($lieu, JSON_UNESCAPED_UNICODE)],
        where: ['event_id' => EVENT_ID],
    );

    app(QrLinkRepositoryInterface::class)->updateWhere(
        attributes: ['name' => 'Wawa 72H 5/5'],
        where: ['id' => QR_ID],
    );

    foreach ($villes as $id => $ville) {
        $attributs = collect(json_decode((string) DB::table('events')->where('id', $id)->value('attributes'), true) ?: [])
            ->reject(fn ($a) => strtolower(trim($a['name'] ?? '')) === 'ville')->values()->all();
        $attributs[] = ['name' => 'ville', 'value' => $ville, 'is_public' => true];
        app(EventRepositoryInterface::class)->updateByIdWhere(
            id: $id,
            attributes: ['attributes' => $attributs],
            where: ['account_id' => ACCOUNT_ID],
        );
    }
});

$e = DB::table('events')->where('id', EVENT_ID)->first();
$s = json_decode((string) DB::table('event_settings')->where('event_id', EVENT_ID)->value('location_details'), true);
echo $e->id.' | '.$e->title.' | '.$e->status.' | lieu : '.($s['venue_name'] ?? '?').', '.($s['city'] ?? '?')."\n";
echo 'Lien 639 : '.DB::table('qr_links')->where('id', QR_ID)->value('name')."\n";
foreach ($villes as $id => $v) {
    echo $id.' | ville : '.(collect(json_decode((string) DB::table('events')->where('id', $id)->value('attributes'), true))->firstWhere('name', 'ville')['value'] ?? '?')."\n";
}
