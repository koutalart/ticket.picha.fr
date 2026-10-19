<?php
// Salon Mayotte Recrute (événement 13) : deux billets seulement, « Candidat » et « Exposant », un par inscription.
// Sur place ou en ligne devient une question obligatoire pour les candidats. Relançable. ESSAI=1 : tout est annulé.

use HiEvents\DomainObjects\Enums\ProductPriceType;
use HiEvents\DomainObjects\Enums\ProductType;
use HiEvents\DomainObjects\Enums\QuestionBelongsTo;
use HiEvents\DomainObjects\Enums\QuestionTypeEnum;
use HiEvents\Services\Application\Handlers\Product\DeleteProductHandler;
use HiEvents\Services\Application\Handlers\Product\DTO\UpsertProductDTO;
use HiEvents\Services\Application\Handlers\Product\EditProductHandler;
use HiEvents\Services\Application\Handlers\Question\CreateQuestionHandler;
use HiEvents\Services\Application\Handlers\Question\DTO\UpsertQuestionDTO;
use HiEvents\Services\Domain\Product\DTO\ProductPriceDTO;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

const ACCOUNT_ID = 7;
const EVENT_ID = 13;
const USER_ID = 11;

$essai = getenv('ESSAI') === '1';

if (DB::table('events')->where('id', EVENT_ID)->where('account_id', ACCOUNT_ID)->value('title') !== 'Salon Mayotte Recrute 2026') {
    throw new RuntimeException('Événement inattendu : rien n\'est modifié');
}
if (DB::table('attendees')->where('event_id', EVENT_ID)->exists()) {
    throw new RuntimeException('Il y a déjà des inscrits : à revoir avant de modifier les billets');
}
$billets = DB::table('products')->where('event_id', EVENT_ID)->whereNull('deleted_at')->get()->keyBy('title');
$candidat = $billets['Candidat sur place'] ?? $billets['Candidat'] ?? null;
$exposant = $billets['Exposant'] ?? null;
if (!$candidat || !$exposant) {
    throw new RuntimeException('Billets inattendus : rien n\'est modifié');
}

auth()->setUser(\HiEvents\Models\User::findOrFail(USER_ID));

DB::beginTransaction();
try {
    app(EditProductHandler::class)->handle(new UpsertProductDTO(
        account_id: ACCOUNT_ID,
        event_id: EVENT_ID,
        product_category_id: $candidat->product_category_id,
        title: 'Candidat',
        type: ProductPriceType::FREE,
        product_type: ProductType::TICKET,
        prices: new Collection([new ProductPriceDTO(price: 0.00)]),
        price: 0.00,
        order: 1,
        max_per_order: 1,
        description: '<p>Le vendredi 23 et le samedi 24 octobre, de 8h30 à 16h30, au lycée des Lumières à Kawéni, ou en ligne dès le 21 octobre depuis chez vous. Une seule inscription suffit pour les deux jours : vous nous direz juste après comment vous comptez participer.</p>',
        min_per_order: 1,
        product_id: $candidat->id,
    ));

    if (isset($billets['Candidat en ligne'])) {
        app(DeleteProductHandler::class)->handle($billets['Candidat en ligne']->id, EVENT_ID);
    }

    if (!DB::table('questions')->where('event_id', EVENT_ID)->where('title', 'Comment participez-vous ?')->whereNull('deleted_at')->exists()) {
        app(CreateQuestionHandler::class)->handle(new UpsertQuestionDTO(
            title: 'Comment participez-vous ?',
            type: QuestionTypeEnum::RADIO,
            required: true,
            options: ['Je viens sur place, au lycée des Lumières', 'Je participe en ligne'],
            event_id: EVENT_ID,
            product_ids: [$candidat->id],
            is_hidden: false,
            belongs_to: QuestionBelongsTo::PRODUCT,
        ));
    }

    if ($essai) {
        DB::rollBack();
        echo "ESSAI OK : tous les traitements passent, rien n'est enregistré\n";
        return;
    }
    DB::commit();
} catch (Throwable $e) {
    DB::rollBack();
    throw $e;
}

echo 'Billets : '.DB::table('products')->where('event_id', EVENT_ID)->whereNull('deleted_at')->get(['title', 'max_per_order'])->map(fn ($p) => $p->title.' (max '.$p->max_per_order.')')->implode(', ')."\n";
echo 'Questions candidat : '.DB::table('questions')->join('product_questions', 'product_questions.question_id', '=', 'questions.id')->where('product_questions.product_id', $candidat->id)->whereNull('questions.deleted_at')->whereNull('product_questions.deleted_at')->pluck('questions.title')->implode(', ')."\n";
