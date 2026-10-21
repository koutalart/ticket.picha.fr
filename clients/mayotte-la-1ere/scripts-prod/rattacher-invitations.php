<?php
// Concert des Générations (compte 5, événement 14) : rattache tous les billets (public + invitations)
// à la liste d'entrée « Entrée du public » et à toutes les questions d'inscription. Relançable.

use HiEvents\DomainObjects\Enums\QuestionBelongsTo;
use HiEvents\DomainObjects\Enums\QuestionTypeEnum;
use HiEvents\Services\Application\Handlers\CheckInList\DTO\UpsertCheckInListDTO;
use HiEvents\Services\Application\Handlers\CheckInList\UpdateCheckInlistHandler;
use HiEvents\Services\Application\Handlers\Question\DTO\UpsertQuestionDTO;
use HiEvents\Services\Application\Handlers\Question\EditQuestionHandler;
use Illuminate\Support\Facades\DB;

const EVENT_ID = 14;

$event = DB::table('events')->where('id', EVENT_ID)->where('account_id', 5)->where('organizer_id', 8)->whereNull('deleted_at')->first();
if (!$event || $event->title !== 'Le Concert des Générations') {
    throw new RuntimeException('Événement inattendu : rien n\'est modifié');
}
$billets = DB::table('products')->where('event_id', EVENT_ID)->whereNull('deleted_at')->orderBy('id')->pluck('id')->all();
$liste = DB::table('check_in_lists')->where('event_id', EVENT_ID)->where('name', 'Entrée du public')->whereNull('deleted_at')->first();
if (count($billets) !== 5 || !$liste) {
    throw new RuntimeException('Billets ou liste inattendus : rien n\'est modifié');
}
$tz = 'Indian/Mayotte';
$local = fn ($utc) => $utc ? \Carbon\Carbon::parse($utc, 'UTC')->setTimezone($tz)->format('Y-m-d H:i:s') : null;

auth()->setUser(\HiEvents\Models\User::findOrFail(8));

DB::transaction(function () use ($billets, $liste, $local) {
    app(UpdateCheckInlistHandler::class)->handle(new UpsertCheckInListDTO(
        name: $liste->name,
        description: $liste->description,
        eventId: EVENT_ID,
        productIds: $billets,
        expiresAt: $local($liste->expires_at),
        activatesAt: $local($liste->activates_at),
        id: $liste->id,
    ));

    foreach (DB::table('questions')->where('event_id', EVENT_ID)->whereNull('deleted_at')->get() as $q) {
        app(EditQuestionHandler::class)->handle($q->id, new UpsertQuestionDTO(
            title: $q->title,
            type: constant(QuestionTypeEnum::class.'::'.$q->type),
            required: (bool) $q->required,
            options: $q->options ? json_decode($q->options, true) : null,
            event_id: EVENT_ID,
            product_ids: $billets,
            is_hidden: (bool) $q->is_hidden,
            belongs_to: constant(QuestionBelongsTo::class.'::'.$q->belongs_to),
            description: $q->description,
        ));
    }
});

$l = DB::table('check_in_lists')->where('id', $liste->id)->first();
echo 'Liste | '.$l->name.' '.$l->activates_at.' → '.$l->expires_at.' UTC | billets '.DB::table('product_check_in_lists')->where('check_in_list_id', $liste->id)->whereNull('deleted_at')->count()."\n";
foreach (DB::table('questions')->where('event_id', EVENT_ID)->whereNull('deleted_at')->get() as $q) {
    echo 'Question | '.$q->title.' | billets '.DB::table('product_questions')->where('question_id', $q->id)->whereNull('deleted_at')->count()."\n";
}
