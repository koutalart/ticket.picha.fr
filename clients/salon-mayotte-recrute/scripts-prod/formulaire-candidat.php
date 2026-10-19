<?php
// Salon Mayotte Recrute (événement 13) : formulaire candidat aligné sur celui de leur site
// (secteurs, mode de participation, CV en PDF, droit à l'image, sans case « actualités »). Relançable. ESSAI=1 : tout est annulé.

use HiEvents\DomainObjects\Enums\QuestionBelongsTo;
use HiEvents\DomainObjects\Enums\QuestionTypeEnum;
use HiEvents\Services\Application\Handlers\EventSettings\DTO\PartialUpdateEventSettingsDTO;
use HiEvents\Services\Application\Handlers\EventSettings\PartialUpdateEventSettingsHandler;
use HiEvents\Services\Application\Handlers\Question\CreateQuestionHandler;
use HiEvents\Services\Application\Handlers\Question\DTO\UpsertQuestionDTO;
use HiEvents\Services\Application\Handlers\Question\EditQuestionHandler;
use HiEvents\Services\Application\Handlers\Question\SortQuestionsHandler;
use Illuminate\Support\Facades\DB;

const EVENT_ID = 13;
const CANDIDAT = 19;
const EXPOSANT = 26;
$essai = getenv('ESSAI') === '1';

if (DB::table('events')->where('id', EVENT_ID)->where('account_id', 7)->value('title') !== 'Salon Mayotte Recrute 2026') {
    throw new RuntimeException('Événement inattendu : rien n\'est modifié');
}
$q = DB::table('questions')->where('event_id', EVENT_ID)->whereNull('deleted_at')->pluck('id', 'title');
$mode = DB::table('questions')->where('event_id', EVENT_ID)->whereNull('deleted_at')->whereIn('title', ['Comment participez-vous ?', 'Mode de participation'])
    ->whereIn('id', DB::table('product_questions')->where('product_id', CANDIDAT)->whereNull('deleted_at')->pluck('question_id'))->value('id');
foreach (['Téléphone', 'Secteur d\'activité principal', 'Droit à l\'image'] as $t) {
    if (!isset($q[$t])) throw new RuntimeException('Question manquante : '.$t);
}
if (!$mode) throw new RuntimeException('Question du mode de participation introuvable');

auth()->setUser(\HiEvents\Models\User::findOrFail(11));
$dto = fn (string $titre, QuestionTypeEnum $type, bool $obligatoire, ?array $options, array $produits) => new UpsertQuestionDTO(
    title: $titre, type: $type, required: $obligatoire, options: $options, event_id: EVENT_ID,
    product_ids: $produits, is_hidden: false, belongs_to: QuestionBelongsTo::PRODUCT,
);

DB::beginTransaction();
try {
    app(EditQuestionHandler::class)->handle($q['Secteur d\'activité principal'], $dto('Secteur d\'activité principal', QuestionTypeEnum::DROPDOWN, false,
        ['IT, Numérique & Tech', 'Commerce, Vente & Marketing', 'Ingénierie & Industrie', 'Administration, RH & Finance', 'Santé & Social', 'Autre'], [CANDIDAT]));
    app(EditQuestionHandler::class)->handle($mode, $dto('Mode de participation', QuestionTypeEnum::RADIO, true,
        ['Sur site (Physique)', 'En ligne (Virtuel)'], [CANDIDAT]));
    app(EditQuestionHandler::class)->handle($q['Droit à l\'image'], $dto('Droit à l\'image', QuestionTypeEnum::CHECKBOX, true,
        ['Je comprends qu\'en participant à cet événement, je suis susceptible d\'être filmé ou photographié. Ces images pourront être utilisées dans le cadre de la promotion du Salon de l\'Emploi, des Métiers et de l\'Attractivité.'],
        [CANDIDAT, EXPOSANT]));

    $cv = DB::table('questions')->where('event_id', EVENT_ID)->where('type', 'FILE')->whereNull('deleted_at')->value('id')
        ?? app(CreateQuestionHandler::class)->handle($dto('Curriculum Vitae (PDF, facultatif mais recommandé)', QuestionTypeEnum::FILE, false, null, [CANDIDAT]))->getId();

    app(PartialUpdateEventSettingsHandler::class)->handle(new PartialUpdateEventSettingsDTO(
        account_id: 7, event_id: EVENT_ID, settings: ['show_marketing_opt_in' => false],
    ));

    $ordre = [$q['Téléphone'], $q['Secteur d\'activité principal'], $mode, $cv, $q['Droit à l\'image']];
    $reste = DB::table('questions')->where('event_id', EVENT_ID)->whereNull('deleted_at')->whereNotIn('id', $ordre)->orderBy('id')->pluck('id')->all();
    app(SortQuestionsHandler::class)->handle(EVENT_ID, collect(array_merge($ordre, $reste))->values()->map(fn ($id, $i) => ['id' => $id, 'order' => $i + 1])->all());

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

echo 'Formulaire candidat : '.DB::table('questions')->join('product_questions', 'product_questions.question_id', '=', 'questions.id')
    ->where('product_questions.product_id', CANDIDAT)->whereNull('questions.deleted_at')->whereNull('product_questions.deleted_at')
    ->orderBy('questions.order')->get(['questions.title', 'questions.type'])->map(fn ($r) => $r->title.' ['.$r->type.']')->implode(', ')."\n";
echo 'Case actualités : '.(DB::table('event_settings')->where('event_id', EVENT_ID)->value('show_marketing_opt_in') ? 'affichée' : 'retirée')."\n";
