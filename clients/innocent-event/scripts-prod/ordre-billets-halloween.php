<?php
// Halloween (événement 7) : vente flash en premier, puis entrée simple, puis sur place. Relançable.
use Illuminate\Support\Facades\DB;

$flash = DB::table('products')->where('event_id', 7)->where('title', '🔥 Vente flash - 15€ (limité)')->whereNull('deleted_at')->value('id');
if (!$flash) {
    throw new RuntimeException('Billet vente flash introuvable : rien n\'est modifié');
}
DB::transaction(function () use ($flash) {
    DB::table('products')->where('id', $flash)->where('event_id', 7)->update(['order' => 1]);
    DB::table('products')->where('id', 10)->where('event_id', 7)->update(['order' => 2]);
    DB::table('products')->where('id', 12)->where('event_id', 7)->update(['order' => 3]);
});
foreach (DB::table('products')->where('event_id', 7)->where('product_category_id', 8)->whereNull('deleted_at')->orderBy('order')->get(['order', 'title']) as $r) {
    echo $r->order.' | '.$r->title."\n";
}
