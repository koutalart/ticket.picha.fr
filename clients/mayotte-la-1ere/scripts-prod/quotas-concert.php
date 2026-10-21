<?php
// Concert des Générations (compte 5, événement 14) : quotas réservés (hypothèses du 22/10, à ajuster par Jo)
// et suppression de la liste d'entrée par défaut « Entrée principale ».
// Jauge totale 315 = public 190 + Chama et remettants 60 + artistes et musiciens 20 + invités Mayotte La 1ère 30 + presse et influenceurs 15.
// Chaque billet réservé est caché et ne s'affiche qu'avec son code d'invitation ; il reste disponible au guichet.
// Relançable : rien n'est créé deux fois.

use HiEvents\DomainObjects\Enums\ProductPriceType;
use HiEvents\DomainObjects\Enums\ProductType;
use HiEvents\DomainObjects\Enums\PromoCodeDiscountTypeEnum;
use HiEvents\DomainObjects\Status\CapacityAssignmentStatus;
use HiEvents\Repository\Interfaces\ProductPriceRepositoryInterface;
use HiEvents\Services\Application\Handlers\CapacityAssignment\CreateCapacityAssignmentHandler;
use HiEvents\Services\Application\Handlers\CapacityAssignment\DTO\UpsertCapacityAssignmentDTO;
use HiEvents\Services\Application\Handlers\CheckInList\DeleteCheckInListHandler;
use HiEvents\Services\Application\Handlers\Product\CreateProductHandler;
use HiEvents\Services\Application\Handlers\Product\DTO\UpsertProductDTO;
use HiEvents\Services\Application\Handlers\PromoCode\CreatePromoCodeHandler;
use HiEvents\Services\Application\Handlers\PromoCode\DTO\UpsertPromoCodeDTO;
use HiEvents\Services\Domain\Product\DTO\ProductPriceDTO;
use Illuminate\Support\Facades\DB;

const ACCOUNT_ID = 5;
const EVENT_ID = 14;
const BILLET_PUBLIC = 27;
const USER_ID = 8;
const PUBLIC_PLACES = 190;
const JAUGE = 315;

$quotas = [
    ['Invitation Chama et remettants', 60, 10, 'CHAMA40'],
    ['Invitation artistes et musiciens', 20, 10, 'ARTISTES40'],
    ['Invitation Mayotte La 1ère', 30, 4, 'INVITES40'],
    ['Invitation presse et influenceurs', 15, 2, 'PRESSE40'],
];

$event = DB::table('events')->where('id', EVENT_ID)->where('account_id', ACCOUNT_ID)->where('organizer_id', 8)->whereNull('deleted_at')->first();
$prix = DB::table('product_prices')->where('product_id', BILLET_PUBLIC)->whereNull('deleted_at')->get(['id']);
if (!$event || $event->title !== 'Le Concert des Générations' || $prix->count() !== 1) {
    throw new RuntimeException('Événement ou billet inattendu : rien n\'est modifié');
}
$categorie = DB::table('products')->where('id', BILLET_PUBLIC)->value('product_category_id');

auth()->setUser(\HiEvents\Models\User::findOrFail(USER_ID));

DB::transaction(function () use ($quotas, $prix, $categorie) {
    app(ProductPriceRepositoryInterface::class)->updateWhere(['initial_quantity_available' => PUBLIC_PLACES], ['id' => $prix->first()->id]);

    $ids = [BILLET_PUBLIC];
    foreach ($quotas as $k => [$titre, $places, $max, $code]) {
        $id = DB::table('products')->where('event_id', EVENT_ID)->where('title', $titre)->whereNull('deleted_at')->value('id');
        if (!$id) {
            $id = app(CreateProductHandler::class)->handle(new UpsertProductDTO(
                account_id: ACCOUNT_ID,
                event_id: EVENT_ID,
                product_category_id: $categorie,
                title: $titre,
                type: ProductPriceType::FREE,
                product_type: ProductType::TICKET,
                prices: collect([new ProductPriceDTO(price: 0.00, initial_quantity_available: $places)]),
                price: 0.00,
                order: $k + 2,
                initial_quantity_available: $places,
                sale_start_date: '2026-10-22 00:00:00',
                sale_end_date: '2026-11-14 17:00:00',
                max_per_order: $max,
                description: 'Place réservée sur invitation. Pièce d\'identité au nom inscrit demandée à l\'entrée.',
                min_per_order: 1,
                is_hidden: false,
                is_hidden_without_promo_code: true,
                is_visible_at_box_office: true,
                is_sales_paused: false,
            ))->getId();
        }
        $ids[] = $id;

        if (!DB::table('promo_codes')->where('event_id', EVENT_ID)->whereRaw('lower(code) = ?', [strtolower($code)])->whereNull('deleted_at')->exists()) {
            app(CreatePromoCodeHandler::class)->handle(EVENT_ID, new UpsertPromoCodeDTO(
                code: $code,
                event_id: EVENT_ID,
                applicable_product_ids: [$id],
                discount_type: PromoCodeDiscountTypeEnum::NONE,
                discount: 0.0,
                expiry_date: '2026-11-14 17:00:00',
                max_allowed_usages: null,
            ));
        }
    }

    if (!DB::table('capacity_assignments')->where('event_id', EVENT_ID)->where('name', 'Jauge totale')->whereNull('deleted_at')->exists()) {
        app(CreateCapacityAssignmentHandler::class)->handle(new UpsertCapacityAssignmentDTO(
            name: 'Jauge totale',
            event_id: EVENT_ID,
            status: CapacityAssignmentStatus::ACTIVE,
            capacity: JAUGE,
            product_ids: $ids,
        ));
    }

    $defaut = DB::table('check_in_lists')->where('event_id', EVENT_ID)->where('name', 'Entrée principale')->whereNull('deleted_at')->value('id');
    if ($defaut && !DB::table('attendee_check_ins')->where('check_in_list_id', $defaut)->exists()) {
        app(DeleteCheckInListHandler::class)->handle(EVENT_ID, $defaut);
    }
});
cache()->forget('event.'.EVENT_ID.'.available_product_quantities');

foreach (DB::table('products as p')->join('product_prices as pp', 'pp.product_id', '=', 'p.id')->where('p.event_id', EVENT_ID)->whereNull('p.deleted_at')
    ->orderBy('p.order')->get(['p.id', 'p.title', 'pp.initial_quantity_available', 'p.max_per_order', 'p.is_hidden_without_promo_code']) as $p) {
    echo 'Billet '.$p->id.' | '.$p->title.' | '.$p->initial_quantity_available.' places | max '.$p->max_per_order.' | '.($p->is_hidden_without_promo_code ? 'sur code' : 'public')."\n";
}
echo 'Codes | '.DB::table('promo_codes')->where('event_id', EVENT_ID)->whereNull('deleted_at')->pluck('code')->implode(', ')."\n";
echo 'Jauge | '.DB::table('capacity_assignments')->where('event_id', EVENT_ID)->whereNull('deleted_at')->value('capacity')."\n";
echo 'Listes | '.DB::table('check_in_lists')->where('event_id', EVENT_ID)->whereNull('deleted_at')->pluck('name')->implode(', ')."\n";
