<?php
// Konka Wawa : les 3 soirées « 72 H à Mayotte » comme la dernière version d'Halloween (événement 7) :
// formulaire d'achat intégré dans la page, sans bloc « À propos » (description vidée, copie gardée ci-dessous).
// Relançable.

use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\EventSettingsRepositoryInterface;
use Illuminate\Support\Facades\DB;

const ACCOUNT_ID = 6;
const ORGANIZER_ID = 9;

$ids = [20, 21, 22];
foreach ($ids as $id) {
    $e = DB::table('events')->where('id', $id)->where('account_id', ACCOUNT_ID)->where('organizer_id', ORGANIZER_ID)->whereNull('deleted_at')->first();
    if (!$e || !str_starts_with($e->title, 'Wawa, 72 H à Mayotte : ')) {
        throw new RuntimeException('Événement '.$id.' inattendu : rien n\'est modifié');
    }
    if ($e->description) {
        echo "Description d'avant ($id) : ".$e->description."\n";
    }
}

DB::transaction(function () use ($ids) {
    foreach ($ids as $id) {
        app(EventSettingsRepositoryInterface::class)->updateWhere(
            attributes: ['embedded_registration_enabled' => true],
            where: ['event_id' => $id],
        );
        app(EventRepositoryInterface::class)->updateByIdWhere(
            id: $id,
            attributes: ['description' => null],
            where: ['account_id' => ACCOUNT_ID],
        );
    }
});

foreach ($ids as $id) {
    echo $id.' | formulaire intégré : '.(DB::table('event_settings')->where('event_id', $id)->value('embedded_registration_enabled') ? 'oui' : 'NON')
        .' | description : '.(DB::table('events')->where('id', $id)->value('description') ? 'présente' : 'vide')."\n";
}
