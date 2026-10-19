<?php
// Halloween (événement 7) : l'entrée simple à 20 € reste masquée en ligne et au guichet
// tant que la vente flash (100 places à 15 €) n'est pas épuisée. Relançable.
use Illuminate\Support\Facades\DB;

$entree = DB::table('products')->where('id', 10)->where('event_id', 7)->where('title', '🎟️ Entrée simple')->whereNull('deleted_at')->first();
if (!$entree) {
    throw new RuntimeException('Entrée simple introuvable : rien n\'est modifié');
}
DB::table('products')->where('id', 10)->where('event_id', 7)->update(['is_hidden' => true, 'is_visible_at_box_office' => false, 'updated_at' => now()]);
foreach (DB::table('products as p')->join('product_prices as pp', 'pp.product_id', '=', 'p.id')->where('p.event_id', 7)->whereNull('p.deleted_at')->orderBy('p.order')
    ->get(['p.title', 'p.is_hidden', 'p.is_visible_at_box_office', 'pp.price', 'pp.initial_quantity_available', 'pp.quantity_sold']) as $r) {
    echo $r->title.' | '.$r->price.' € | en ligne : '.($r->is_hidden ? 'masqué' : 'visible').' | guichet : '.($r->is_visible_at_box_office ? 'oui' : 'non').' | vendus '.$r->quantity_sold.'/'.($r->initial_quantity_available ?? '∞')."\n";
}
echo 'Code tinemada limité à : '.DB::table('promo_codes')->where('event_id', 7)->where('code', 'tinemada')->value('applicable_product_ids')."\n";
