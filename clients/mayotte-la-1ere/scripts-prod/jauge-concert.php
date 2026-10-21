<?php
// Concert des Générations (événement 14, billet 27) : jauge de 300 places sur le tarif du billet.
use Illuminate\Support\Facades\DB;

$prix = DB::table('product_prices as pp')->join('products as p', 'p.id', '=', 'pp.product_id')
    ->where('p.id', 27)->where('p.event_id', 14)->whereNull('pp.deleted_at')->get(['pp.id']);
if ($prix->count() !== 1 || DB::table('events')->where('id', 14)->value('account_id') !== 5) {
    throw new RuntimeException('Billet inattendu : rien n\'est modifié');
}
DB::table('product_prices')->where('id', $prix->first()->id)->update(['initial_quantity_available' => 300, 'updated_at' => now()]);
cache()->forget('event.14.available_product_quantities');
$q = app(\HiEvents\Services\Domain\Product\AvailableProductQuantitiesFetchService::class)->getAvailableProductQuantities(14, true);
echo 'Billet 27 | jauge : '.DB::table('product_prices')->where('id', $prix->first()->id)->value('initial_quantity_available').' | places restantes : '.json_encode($q->productQuantities->first()?->quantity_available)."\n";
