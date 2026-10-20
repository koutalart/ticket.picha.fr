<?php
// Halloween (événement 7) : l'entrée simple (produit 10) s'ouvre d'elle-même quand la vente flash (produit 20) est épuisée.
// La plateforme la cache en ligne et au guichet d'ici là (PR #130). Relançable.
use Illuminate\Support\Facades\DB;

$entree = DB::table('products')->where('id', 10)->where('event_id', 7)->where('title', '🎟️ Entrée simple')->whereNull('deleted_at')->first();
$flash = DB::table('products')->where('id', 20)->where('event_id', 7)->where('title', '🔥 Vente flash - 15€ (limité)')->whereNull('deleted_at')->first();
if (!$entree || !$flash) {
    throw new RuntimeException('Billets inattendus : rien n\'est modifié');
}
DB::table('products')->where('id', 10)->update(['unlock_after_product_id' => 20, 'is_hidden' => false, 'is_visible_at_box_office' => true, 'updated_at' => now()]);
$e = DB::table('products')->where('id', 10)->first(['is_hidden', 'is_visible_at_box_office', 'unlock_after_product_id']);
echo 'Entrée simple : ouverture après le produit '.$e->unlock_after_product_id.' | masquée '.($e->is_hidden ? 'oui' : 'non').' | guichet '.($e->is_visible_at_box_office ? 'oui' : 'non')."\n";
