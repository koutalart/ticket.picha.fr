<?php
// Salon Mayotte Recrute (événement 13) : plusieurs candidats par inscription (structures qui inscrivent un groupe). Relançable.

use HiEvents\DomainObjects\Enums\ProductPriceType;
use HiEvents\DomainObjects\Enums\ProductType;
use HiEvents\Services\Application\Handlers\Product\DTO\UpsertProductDTO;
use HiEvents\Services\Application\Handlers\Product\EditProductHandler;
use HiEvents\Services\Domain\Product\DTO\ProductPriceDTO;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

$p = DB::table('products')->where('id', 19)->where('event_id', 13)->whereNull('deleted_at')->first();
if (!$p || $p->title !== 'Candidat') {
    throw new RuntimeException('Billet inattendu : rien n\'est modifié');
}
auth()->setUser(\HiEvents\Models\User::findOrFail(11));
app(EditProductHandler::class)->handle(new UpsertProductDTO(
    account_id: 7,
    event_id: 13,
    product_category_id: $p->product_category_id,
    title: 'Candidat',
    type: ProductPriceType::FREE,
    product_type: ProductType::TICKET,
    prices: new Collection([new ProductPriceDTO(price: 0.00)]),
    price: 0.00,
    order: 1,
    max_per_order: 100,
    description: $p->description,
    min_per_order: 1,
    product_id: 19,
));
echo 'Candidat : max '.DB::table('products')->where('id', 19)->value('max_per_order').' par inscription'."\n";
