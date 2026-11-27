<?php
// Konka Wawa : téléphone obligatoire à l'achat sur les 3 soirées « 72 H à Mayotte »,
// comme Halloween (question 3 de l'événement 7 : « Numéro de téléphone », PHONE, ORDER, obligatoire). Relançable.

use HiEvents\DomainObjects\Enums\QuestionBelongsTo;
use HiEvents\DomainObjects\Enums\QuestionTypeEnum;
use HiEvents\Services\Application\Handlers\Question\CreateQuestionHandler;
use HiEvents\Services\Application\Handlers\Question\DTO\UpsertQuestionDTO;
use Illuminate\Support\Facades\DB;

const ACCOUNT_ID = 6;
const ORGANIZER_ID = 9;
const USER_ID = 10;

auth()->setUser(\HiEvents\Models\User::findOrFail(USER_ID));

foreach ([20, 21, 22] as $id) {
    $e = DB::table('events')->where('id', $id)->where('account_id', ACCOUNT_ID)->where('organizer_id', ORGANIZER_ID)->whereNull('deleted_at')->first();
    if (!$e || !str_starts_with($e->title, 'Wawa, 72 H à Mayotte : ')) {
        throw new RuntimeException('Événement '.$id.' inattendu');
    }
    $existe = DB::table('questions')->where('event_id', $id)->where('type', 'PHONE')->where('belongs_to', 'ORDER')->whereNull('deleted_at')->exists();
    if (!$existe) {
        app(CreateQuestionHandler::class)->handle(new UpsertQuestionDTO(
            title: 'Numéro de téléphone',
            type: QuestionTypeEnum::PHONE,
            required: true,
            options: null,
            event_id: $id,
            product_ids: [],
            is_hidden: false,
            belongs_to: QuestionBelongsTo::ORDER,
        ));
    }
    $q = DB::table('questions')->where('event_id', $id)->where('type', 'PHONE')->whereNull('deleted_at')->first();
    echo $id.' | '.$q->title.' | '.$q->belongs_to.' | obligatoire : '.($q->required ? 'oui' : 'NON')."\n";
}
