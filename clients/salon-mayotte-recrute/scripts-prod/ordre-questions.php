<?php
// Salon Mayotte Recrute (événement 13) : ordre des questions, le droit à l'image en dernier pour les deux parcours. Relançable.

use HiEvents\Services\Application\Handlers\Question\SortQuestionsHandler;
use Illuminate\Support\Facades\DB;

if (DB::table('events')->where('id', 13)->where('account_id', 7)->value('title') !== 'Salon Mayotte Recrute 2026') {
    throw new RuntimeException('Événement inattendu : rien n\'est modifié');
}
$titres = ['Téléphone', 'Secteur d\'activité principal', 'Mode de participation', 'Curriculum Vitae (PDF, facultatif mais recommandé)',
    'Entreprise ou organisme', 'Fonction du contact', 'Téléphone du contact', 'Secteur d\'activité de l\'entreprise',
    'Nombre de recruteurs présents', 'Postes ou métiers proposés', 'Droit à l\'image'];
$questions = DB::table('questions')->where('event_id', 13)->whereNull('deleted_at')->get(['id', 'title']);
$exposant = DB::table('product_questions')->where('product_id', 26)->whereNull('deleted_at')->pluck('question_id')->all();
$modeExposant = $questions->where('title', 'Mode de participation')->whereIn('id', $exposant)->pluck('id')->all();
$ordre = [];
foreach ($titres as $t) {
    foreach ($questions->where('title', $t)->whereNotIn('id', $t === 'Mode de participation' ? $modeExposant : [])->sortBy('id') as $q) $ordre[] = $q->id;
    if ($t === 'Secteur d\'activité de l\'entreprise') array_push($ordre, ...$modeExposant);
}
$ordre = array_values(array_unique(array_merge($ordre, $questions->pluck('id')->sort()->all())));
auth()->setUser(\HiEvents\Models\User::findOrFail(11));
app(SortQuestionsHandler::class)->handle(13, array_map(fn ($id, $i) => ['id' => $id, 'order' => $i + 1], $ordre, array_keys($ordre)));
echo 'Ordre : '.DB::table('questions')->where('event_id', 13)->whereNull('deleted_at')->orderBy('order')->pluck('title')->implode(' > ')."\n";
