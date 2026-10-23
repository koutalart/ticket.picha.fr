<?php
// Option sérénité (produit 33, événement 15) : réglage « Produit obligatoire » activé (#230). Rejouable, tous les autres champs restent identiques.

use HiEvents\DomainObjects\Enums\ProductPriceType;
use HiEvents\DomainObjects\Enums\ProductType;
use HiEvents\Services\Application\Handlers\Product\DTO\UpsertProductDTO;
use HiEvents\Services\Application\Handlers\Product\EditProductHandler;
use HiEvents\Services\Domain\Product\DTO\ProductPriceDTO;
use Illuminate\Support\Facades\DB;

const ACCOUNT_ID = 3;
const EVENT_ID = 15;
const PRODUCT_ID = 33;
const USER_ID = 6;

$p = DB::table('products')->where('id', PRODUCT_ID)->where('event_id', EVENT_ID)->whereNull('deleted_at')->first();
$e = DB::table('events')->where('id', EVENT_ID)->where('account_id', ACCOUNT_ID)->where('organizer_id', 14)->first();
if (!$p || !$e || !str_contains($p->title, 'Option sérénité')) {
    throw new RuntimeException('Produit ou événement inattendu : rien n\'est modifié');
}
$prix = DB::table('product_prices')->where('product_id', PRODUCT_ID)->whereNull('deleted_at')->get();
if ($prix->count() !== 1) {
    throw new RuntimeException('Le produit n\'a pas un seul prix : rien n\'est modifié');
}
$taxes = DB::table('product_taxes_and_fees')->where('product_id', PRODUCT_ID)->pluck('tax_and_fee_id')->all();

auth()->setUser(\HiEvents\Models\User::findOrFail(USER_ID));

app(EditProductHandler::class)->handle(new UpsertProductDTO(
    account_id: ACCOUNT_ID,
    event_id: EVENT_ID,
    product_category_id: $p->product_category_id,
    title: $p->title,
    type: constant(ProductPriceType::class.'::'.$p->type),
    product_type: constant(ProductType::class.'::'.$p->product_type),
    prices: collect([new ProductPriceDTO(
        price: (float) $prix[0]->price,
        label: $prix[0]->label,
        initial_quantity_available: $prix[0]->initial_quantity_available,
        is_hidden: (bool) $prix[0]->is_hidden,
        id: $prix[0]->id,
    )]),
    price: (float) $prix[0]->price,
    order: $p->order,
    max_per_order: $p->max_per_order,
    description: $p->description,
    min_per_order: $p->min_per_order,
    is_hidden: (bool) $p->is_hidden,
    hide_before_sale_start_date: (bool) $p->hide_before_sale_start_date,
    hide_after_sale_end_date: (bool) $p->hide_after_sale_end_date,
    hide_when_sold_out: (bool) $p->hide_when_sold_out,
    start_collapsed: (bool) $p->start_collapsed,
    show_quantity_remaining: (bool) $p->show_quantity_remaining,
    is_hidden_without_promo_code: (bool) $p->is_hidden_without_promo_code,
    is_visible_at_box_office: (bool) $p->is_visible_at_box_office,
    tax_and_fee_ids: $taxes,
    product_id: PRODUCT_ID,
    is_highlighted: (bool) $p->is_highlighted,
    highlight_message: $p->highlight_message,
    waitlist_enabled: $p->waitlist_enabled === null ? null : (bool) $p->waitlist_enabled,
    unlock_after_product_id: $p->unlock_after_product_id,
    is_required: true,
));

foreach (DB::table('products')->whereIn('id', [3, 13, 32, 33])->orderBy('id')->get() as $r) {
    $pr = DB::table('product_prices')->where('product_id', $r->id)->whereNull('deleted_at')->pluck('price')->implode('/');
    echo "{$r->id} | ev {$r->event_id} | {$r->title} | {$pr} € | obligatoire ".($r->is_required ? 'oui' : 'non')." | min {$r->min_per_order} max {$r->max_per_order}\n";
}
