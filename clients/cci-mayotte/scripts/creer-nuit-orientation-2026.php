<?php
// La Nuit de l'Orientation 2026 (CCI Mayotte, compte 18, organisateur 22) : événement en brouillon, entrée gratuite sans jauge
// (1 à 40 places par inscription), questions de profil par commande. Rien n'est créé si un contrôle échoue ou si l'événement existe déjà.

use HiEvents\DataTransferObjects\AddressDTO;
use HiEvents\DomainObjects\Enums\EventCategory;
use HiEvents\DomainObjects\Enums\ProductPriceType;
use HiEvents\DomainObjects\Enums\ProductType;
use HiEvents\DomainObjects\Enums\QuestionBelongsTo;
use HiEvents\DomainObjects\Enums\QuestionTypeEnum;
use HiEvents\DomainObjects\Status\EventStatus;
use HiEvents\Http\Request\EventSettings\UpdateEventSettingsRequest;
use HiEvents\Services\Application\Handlers\Event\CreateEventHandler;
use HiEvents\Services\Application\Handlers\Event\DTO\CreateEventDTO;
use HiEvents\Services\Application\Handlers\EventSettings\DTO\PartialUpdateEventSettingsDTO;
use HiEvents\Services\Application\Handlers\EventSettings\PartialUpdateEventSettingsHandler;
use HiEvents\Services\Application\Handlers\Product\CreateProductHandler;
use HiEvents\Services\Application\Handlers\Product\DTO\UpsertProductDTO;
use HiEvents\Services\Application\Handlers\Question\CreateQuestionHandler;
use HiEvents\Services\Application\Handlers\Question\DTO\UpsertQuestionDTO;
use HiEvents\Services\Domain\Product\DTO\ProductPriceDTO;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

const ACCOUNT_ID = 18;
const ORGANIZER_ID = 22;
const USER_ID = 22;
const TITRE = 'La Nuit de l\'Orientation';

$org = DB::table('organizers')->where('id', ORGANIZER_ID)->where('account_id', ACCOUNT_ID)->whereNull('deleted_at')->first();
if (!$org || $org->name !== 'CCI Mayotte') {
    throw new RuntimeException('Organisateur 22 inattendu : rien n\'est créé');
}
if (DB::table('events')->where('organizer_id', ORGANIZER_ID)->where('title', TITRE)->whereNull('deleted_at')->exists()) {
    throw new RuntimeException('« '.TITRE.' » existe déjà : rien n\'est créé');
}

$description = '<p><strong>La Nuit de l\'Orientation revient le samedi 5 décembre 2026, de 8h00 à 12h30, au Technopôle de Dembéni.</strong></p>'
    .'<p>Collégiens, lycéens, étudiants, parents ou demandeurs d\'emploi, venez rencontrer des professionnels et découvrir leurs métiers.</p>'
    .'<p>Au programme : démonstrations métiers, conférences, stands, ateliers, animations et job dating.</p>'
    .'<p>L\'entrée est gratuite, sur inscription. Un professeur peut inscrire sa classe en une seule fois, jusqu\'à 40 places par inscription.</p>'
    .'<p>Avec Le mois de la découverte des métiers, le Centre d\'Étude de Langues, ELO, Negoventis, l\'ANAF, le Département de Mayotte, la CADEMA et la CCI Mayotte.</p>';

$lieu = [
    'venue_name' => 'Technopôle de Dembéni',
    'address_line_1' => 'Technopôle de Dembéni',
    'city' => 'Dembéni',
    'zip_or_postal_code' => '97660',
    'country' => 'YT',
];

$reglages = [
    'attendee_details_collection_method' => 'PER_ORDER',
    'pass_platform_fee_to_buyer' => true,
    'support_email' => 'cmkadara@mayotte.cci.fr',
    'organization_name' => 'CCI Mayotte',
    'show_marketing_opt_in' => true,
    'post_checkout_message' => '<p><strong>Merci pour votre inscription à la Nuit de l\'Orientation !</strong></p>'
        .'<p>Rendez-vous samedi 5 décembre dès 8h00 au Technopôle de Dembéni. Présentez votre billet (QR code) à l\'entrée.</p>',
    'location_details' => $lieu,
];
$regles = array_filter((new UpdateEventSettingsRequest())->rules(),
    fn ($cle) => in_array(explode('.', $cle)[0], array_keys($reglages), true), ARRAY_FILTER_USE_KEY);
Validator::make($reglages, $regles)->validate();

auth()->setUser(\HiEvents\Models\User::findOrFail(USER_ID));

$event = DB::transaction(function () use ($description, $reglages, $lieu) {
    $event = app(CreateEventHandler::class)->handle(new CreateEventDTO(
        title: TITRE,
        organizer_id: ORGANIZER_ID,
        account_id: ACCOUNT_ID,
        user_id: USER_ID,
        start_date: '2026-12-05 08:00:00',
        end_date: '2026-12-05 12:30:00',
        description: $description,
        timezone: 'Indian/Mayotte',
        currency: 'EUR',
        category: EventCategory::EDUCATION,
        location_details: new AddressDTO(...$lieu),
        status: EventStatus::DRAFT->name,
    ));
    $eventId = $event->getId();

    app(PartialUpdateEventSettingsHandler::class)->handle(new PartialUpdateEventSettingsDTO(
        account_id: ACCOUNT_ID,
        event_id: $eventId,
        settings: $reglages,
    ));

    $categorie = DB::table('product_categories')->where('event_id', $eventId)->whereNull('deleted_at')->orderBy('id')->value('id');
    $produit = app(CreateProductHandler::class)->handle(new UpsertProductDTO(
        account_id: ACCOUNT_ID,
        event_id: $eventId,
        product_category_id: $categorie,
        title: 'Entrée gratuite',
        type: ProductPriceType::FREE,
        product_type: ProductType::TICKET,
        prices: collect([new ProductPriceDTO(price: 0.00)]),
        price: 0.00,
        order: 1,
        max_per_order: 40,
        min_per_order: 1,
        description: '<p>Entrée libre de 8h00 à 12h30. Pour une classe, choisissez le nombre d\'élèves (jusqu\'à 40).</p>',
    ));

    $questions = [
        ['Vous êtes', QuestionTypeEnum::RADIO, true, ['Collégien', 'Lycéen', 'Étudiant', 'Parent', 'Professeur (inscription d\'une classe)', 'Demandeur d\'emploi', 'Professionnel', 'Autre']],
        ['Établissement ou école', QuestionTypeEnum::SINGLE_LINE_TEXT, false, null],
        ['Commune', QuestionTypeEnum::SINGLE_LINE_TEXT, true, null],
        ['Ce qui vous intéresse', QuestionTypeEnum::CHECKBOX, false, ['Démonstrations métiers', 'Conférences', 'Stands', 'Ateliers', 'Animations', 'Job dating']],
    ];
    foreach ($questions as [$titre, $type, $obligatoire, $options]) {
        app(CreateQuestionHandler::class)->handle(new UpsertQuestionDTO(
            title: $titre,
            type: $type,
            required: $obligatoire,
            options: $options,
            event_id: $eventId,
            product_ids: [$produit->getId()],
            is_hidden: false,
            belongs_to: QuestionBelongsTo::ORDER,
        ));
    }

    return $event;
});

$eventId = $event->getId();
$e = DB::table('events')->where('id', $eventId)->first();
$s = DB::table('event_settings')->where('event_id', $eventId)->first();
echo "Événement {$e->id} | {$e->title} | {$e->status} | {$e->start_date} → {$e->end_date} UTC | short_id {$e->short_id}\n";
echo 'Frais acheteur '.var_export((bool) $s->pass_platform_fee_to_buyer, true).' | infos '.$s->attendee_details_collection_method."\n";
foreach (DB::table('products')->where('event_id', $eventId)->whereNull('deleted_at')->get() as $p) {
    echo "Produit {$p->id} | {$p->title} | {$p->type} | min {$p->min_per_order} max {$p->max_per_order}\n";
}
foreach (DB::table('questions')->where('event_id', $eventId)->whereNull('deleted_at')->orderBy('id')->get() as $q) {
    echo "Question {$q->id} | {$q->title} | {$q->type} | ".($q->required ? 'obligatoire' : 'facultative')." | {$q->options}\n";
}
