<?php
// Salon Mayotte Recrute (événement 13) : un exposant est forcément sur place. On retire sa question
// « Mode de participation » et on ajuste le texte de son billet. Relançable.

use HiEvents\DomainObjects\Enums\ProductPriceType;
use HiEvents\DomainObjects\Enums\ProductType;
use HiEvents\Services\Application\Handlers\Product\DTO\UpsertProductDTO;
use HiEvents\Services\Application\Handlers\Product\EditProductHandler;
use HiEvents\Services\Application\Handlers\Question\DeleteQuestionHandler;
use HiEvents\Services\Domain\Product\DTO\ProductPriceDTO;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

const EXPOSANT = 26;
$p = DB::table('products')->where('id', EXPOSANT)->where('event_id', 13)->whereNull('deleted_at')->first();
if (!$p || $p->title !== 'Exposant') {
    throw new RuntimeException('Billet inattendu : rien n\'est modifié');
}
auth()->setUser(\HiEvents\Models\User::findOrFail(11));

$mode = DB::table('questions')->join('product_questions', 'product_questions.question_id', '=', 'questions.id')
    ->where('questions.event_id', 13)->where('questions.title', 'Mode de participation')->where('product_questions.product_id', EXPOSANT)
    ->whereNull('questions.deleted_at')->whereNull('product_questions.deleted_at')->value('questions.id');
if ($mode) {
    app(DeleteQuestionHandler::class)->handle($mode, 13);
}

app(EditProductHandler::class)->handle(new UpsertProductDTO(
    account_id: 7,
    event_id: 13,
    product_category_id: $p->product_category_id,
    title: 'Exposant',
    type: ProductPriceType::FREE,
    product_type: ProductType::TICKET,
    prices: new Collection([new ProductPriceDTO(price: 0.00)]),
    price: 0.00,
    order: 2,
    max_per_order: 1,
    description: '<p>Dites-nous qui vous êtes et ce que vous proposez. Notre équipe vous rappelle rapidement pour organiser votre stand au lycée des Lumières à Kawéni.</p>',
    min_per_order: 1,
    product_id: EXPOSANT,
));

echo 'Questions exposant : '.DB::table('questions')->join('product_questions', 'product_questions.question_id', '=', 'questions.id')
    ->where('product_questions.product_id', EXPOSANT)->whereNull('questions.deleted_at')->whereNull('product_questions.deleted_at')
    ->orderBy('questions.order')->pluck('questions.title')->implode(' > ')."\n";
