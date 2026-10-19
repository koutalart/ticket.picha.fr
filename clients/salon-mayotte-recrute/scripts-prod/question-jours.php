<?php
// Salon Mayotte Recrute (événement 13) : choix du ou des jours (vendredi 23, samedi 24), candidats et exposants. Relançable.

use HiEvents\DomainObjects\Enums\QuestionBelongsTo;
use HiEvents\DomainObjects\Enums\QuestionTypeEnum;
use HiEvents\Services\Application\Handlers\Question\CreateQuestionHandler;
use HiEvents\Services\Application\Handlers\Question\DTO\UpsertQuestionDTO;
use HiEvents\Services\Application\Handlers\Question\SortQuestionsHandler;
use Illuminate\Support\Facades\DB;

const TITRE = 'Quel jour venez-vous ?';
if (DB::table('events')->where('id', 13)->where('account_id', 7)->value('title') !== 'Salon Mayotte Recrute 2026') {
    throw new RuntimeException('Événement inattendu : rien n\'est modifié');
}
auth()->setUser(\HiEvents\Models\User::findOrFail(11));

$id = DB::table('questions')->where('event_id', 13)->where('title', TITRE)->whereNull('deleted_at')->value('id')
    ?? app(CreateQuestionHandler::class)->handle(new UpsertQuestionDTO(
        title: TITRE,
        type: QuestionTypeEnum::CHECKBOX,
        required: true,
        options: ['Vendredi 23 octobre', 'Samedi 24 octobre'],
        event_id: 13,
        product_ids: [19, 26],
        is_hidden: false,
        belongs_to: QuestionBelongsTo::PRODUCT,
        description: 'Cochez un jour ou les deux.',
    ))->getId();

// juste après le mode de participation, pour chaque parcours
$ids = DB::table('questions')->where('event_id', 13)->whereNull('deleted_at')->where('id', '!=', $id)->orderBy('order')->orderBy('id')->get(['id', 'title']);
$ordre = [];
foreach ($ids as $q) {
    $ordre[] = $q->id;
    if ($q->title === 'Mode de participation' && !in_array($id, $ordre, true)) $ordre[] = $id;
}
app(SortQuestionsHandler::class)->handle(13, array_map(fn ($q, $i) => ['id' => $q, 'order' => $i + 1], $ordre, array_keys($ordre)));
echo 'Ordre : '.DB::table('questions')->where('event_id', 13)->whereNull('deleted_at')->orderBy('order')->pluck('title')->implode(' > ')."\n";
