<?php
// Concert des Générations (compte 5, événement 14) : réglages d'inscription validés (plan, points 1 à 4, 8 et 9).
// Réglages validés avec les règles du formulaire de la plateforme ; questions et liste d'entrée créées par les traitements de l'appli.
// Relançable : les questions et la liste ne sont créées qu'une fois.

use HiEvents\DomainObjects\Enums\QuestionBelongsTo;
use HiEvents\DomainObjects\Enums\QuestionTypeEnum;
use HiEvents\Http\Request\EventSettings\UpdateEventSettingsRequest;
use HiEvents\Services\Application\Handlers\CheckInList\CreateCheckInListHandler;
use HiEvents\Services\Application\Handlers\CheckInList\DTO\UpsertCheckInListDTO;
use HiEvents\Services\Application\Handlers\EventSettings\DTO\PartialUpdateEventSettingsDTO;
use HiEvents\Services\Application\Handlers\EventSettings\PartialUpdateEventSettingsHandler;
use HiEvents\Services\Application\Handlers\Question\CreateQuestionHandler;
use HiEvents\Services\Application\Handlers\Question\DTO\UpsertQuestionDTO;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

const ACCOUNT_ID = 5;
const EVENT_ID = 14;
const BILLET_ID = 27;
const USER_ID = 8;

$event = DB::table('events')->where('id', EVENT_ID)->where('account_id', ACCOUNT_ID)->where('organizer_id', 8)->whereNull('deleted_at')->first();
if (!$event || $event->title !== 'Le Concert des Générations'
    || !DB::table('products')->where('id', BILLET_ID)->where('event_id', EVENT_ID)->whereNull('deleted_at')->exists()) {
    throw new RuntimeException('Événement ou billet inattendu : rien n\'est modifié');
}

$design = json_decode(DB::table('event_settings')->where('event_id', EVENT_ID)->value('ticket_design_settings') ?? '{}', true) ?: [];
$reglages = [
    // 1. aide sur le nom
    'pre_checkout_message' => '<p><strong>Indiquez pour chaque personne son nom et son prénom tels qu\'ils figurent sur sa pièce d\'identité.</strong><br />Ils seront vérifiés à l\'entrée, avec la pièce d\'identité.</p>',
    // 4. billet et e-mails
    'ticket_design_settings' => array_merge($design, [
        'accent_color' => '#b8963e',
        'footer_text' => 'Pièce d\'identité obligatoire à l\'entrée, au nom inscrit sur ce billet. Accueil à partir de 18h30.',
    ]),
    'email_footer_message' => '<p>Pièce d\'identité obligatoire à l\'entrée, au nom inscrit sur le billet.</p>',
    // 8. liste d'attente automatique, 24 h pour accepter
    'waitlist_auto_process' => true,
    'waitlist_offer_timeout_minutes' => 1440,
];
$cles = ['pre_checkout_message', 'ticket_design_settings', 'email_footer_message', 'waitlist_auto_process', 'waitlist_offer_timeout_minutes'];
$regles = array_filter((new UpdateEventSettingsRequest())->rules(),
    fn ($cle) => in_array(explode('.', $cle)[0], $cles, true), ARRAY_FILTER_USE_KEY);
Validator::make($reglages, $regles)->validate();

auth()->setUser(\HiEvents\Models\User::findOrFail(USER_ID));

DB::transaction(function () use ($reglages) {
    app(PartialUpdateEventSettingsHandler::class)->handle(new PartialUpdateEventSettingsDTO(
        account_id: ACCOUNT_ID,
        event_id: EVENT_ID,
        settings: $reglages,
    ));

    $question = function (string $titre, QuestionTypeEnum $type, bool $obligatoire, array $options, QuestionBelongsTo $pour) {
        if (DB::table('questions')->where('event_id', EVENT_ID)->where('title', $titre)->whereNull('deleted_at')->exists()) {
            return;
        }
        app(CreateQuestionHandler::class)->handle(new UpsertQuestionDTO(
            title: $titre,
            type: $type,
            required: $obligatoire,
            options: $options,
            event_id: EVENT_ID,
            product_ids: [BILLET_ID],
            is_hidden: false,
            belongs_to: $pour,
        ));
    };
    // 2. consentement RGPD obligatoire (par commande)
    $question('Protection des données', QuestionTypeEnum::CHECKBOX, true,
        ['J\'accepte que mes données soient utilisées par Mayotte La 1ère et KULTUR\'M uniquement pour gérer l\'accès à la soirée et sa sécurité, puis supprimées après le bilan de l\'événement'],
        QuestionBelongsTo::ORDER);
    // 3. mineur (par billet)
    $question('Cette personne est-elle mineure ?', QuestionTypeEnum::RADIO, true, ['Non', 'Oui'], QuestionBelongsTo::PRODUCT);

    // 9. liste de contrôle des entrées
    if (!DB::table('check_in_lists')->where('event_id', EVENT_ID)->where('name', 'Entrée du public')->whereNull('deleted_at')->exists()) {
        app(CreateCheckInListHandler::class)->handle(new UpsertCheckInListDTO(
            name: 'Entrée du public',
            description: 'Scan du QR code et vérification de la pièce d\'identité (nom identique au billet).',
            eventId: EVENT_ID,
            productIds: [BILLET_ID],
            expiresAt: '2026-11-14 22:30:00',
            activatesAt: '2026-11-14 16:30:00',
        ));
    }
});

$s = DB::table('event_settings')->where('event_id', EVENT_ID)->first();
echo 'Réglages | billet '.$s->ticket_design_settings.' | attente auto '.($s->waitlist_auto_process ? 'oui' : 'non').' '.$s->waitlist_offer_timeout_minutes." min\n";
echo 'Questions | '.DB::table('questions')->where('event_id', EVENT_ID)->whereNull('deleted_at')->pluck('title')->implode(' / ')."\n";
$l = DB::table('check_in_lists')->where('event_id', EVENT_ID)->whereNull('deleted_at')->first(['name', 'activates_at', 'expires_at']);
echo 'Liste | '.($l ? $l->name.' '.$l->activates_at.' → '.$l->expires_at.' UTC' : 'ABSENTE')."\n";
