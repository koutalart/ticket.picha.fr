<?php
// Mayotte la 1ère (compte 5, organisateur 8) : page organisateur mise à jour et publiée,
// création du « Concert des Générations » (40 ans de la télévision à Mayotte, 14/11/2026).
// Rien n'est écrit si un contrôle échoue ou si l'événement existe déjà.

use HiEvents\DataTransferObjects\AddressDTO;
use HiEvents\DomainObjects\Enums\EventCategory;
use HiEvents\DomainObjects\Enums\ProductPriceType;
use HiEvents\DomainObjects\Enums\ProductType;
use HiEvents\DomainObjects\Enums\QuestionBelongsTo;
use HiEvents\DomainObjects\Enums\QuestionTypeEnum;
use HiEvents\DomainObjects\Status\EventStatus;
use HiEvents\DomainObjects\Status\OrganizerStatus;
use HiEvents\Services\Application\Handlers\Event\CreateEventHandler;
use HiEvents\Services\Application\Handlers\Event\DTO\CreateEventDTO;
use HiEvents\Services\Application\Handlers\EventSettings\DTO\PartialUpdateEventSettingsDTO;
use HiEvents\Services\Application\Handlers\EventSettings\PartialUpdateEventSettingsHandler;
use HiEvents\Services\Application\Handlers\Organizer\DTO\EditOrganizerDTO;
use HiEvents\Services\Application\Handlers\Organizer\DTO\UpdateOrganizerStatusDTO;
use HiEvents\Services\Application\Handlers\Organizer\EditOrganizerHandler;
use HiEvents\Services\Application\Handlers\Organizer\UpdateOrganizerStatusHandler;
use HiEvents\Services\Application\Handlers\Product\CreateProductHandler;
use HiEvents\Services\Application\Handlers\Product\DTO\UpsertProductDTO;
use HiEvents\Services\Application\Handlers\Question\CreateQuestionHandler;
use HiEvents\Services\Application\Handlers\Question\DTO\UpsertQuestionDTO;
use HiEvents\Services\Domain\Product\DTO\ProductPriceDTO;
use Illuminate\Support\Facades\DB;

const ACCOUNT_ID = 5;
const ORGANIZER_ID = 8;
const USER_ID = 8;
const TITRE = 'Le Concert des Générations';

$organisateur = '<p><strong>Mayotte La 1ère</strong>, votre chaîne de télévision, de radio et de numérique, célèbre en 2026 les 40 ans de la télévision à Mayotte.</p>'
    .'<p>Retrouvez ici tous les rendez-vous du programme #40ansTVMayotte ouverts au public : journées portes ouvertes, concerts et soirées, gratuits sur inscription.</p>';

$description = '<p><strong>40 ans de télé, 40 ans de musique : les générations se rencontrent.</strong></p>'
    .'<p>Pour célébrer les 40 ans de la télévision à Mayotte, Mayotte La 1ère vous invite au Concert des Générations, une soirée exceptionnelle enregistrée en public dans ses locaux, le samedi 14 novembre 2026.</p>'
    .'<p>Huit artistes de la scène actuelle rendent hommage à celles et ceux qui les ont précédés : chacun revisite les chansons qui ont marqué Mayotte, puis interprète un titre personnel, accompagné en live par un orchestre de musiciens professionnels de Mayotte.</p>'
    .'<p><strong>Sur scène</strong><br />Méli, RH, Chaldi, Jah D One, Kemaal &amp; O.B., Annice, Maliza, et un dernier artiste bientôt annoncé.</p>'
    .'<p><strong>Le déroulé de la soirée</strong><br />18h30 : accueil du public, photocall et cocktail<br />20h15 : le concert<br />22h00 : photo officielle</p>'
    .'<p>La soirée est précédée, à 17h, d\'une cérémonie de remise de trophées à une quarantaine de Chama de Debaa et de Chigoma, sur invitation.</p>'
    .'<p><strong>Entrée gratuite, sur inscription, dans la limite des places disponibles.</strong><br />Jusqu\'à 2 places par inscription, chaque personne étant nommée. Une pièce d\'identité au nom de l\'inscription sera demandée à l\'entrée.<br />Inscriptions du lundi 26 octobre au vendredi 13 novembre à midi.</p>'
    .'<p>Vous ne pourrez pas venir ? Rendez-vous le lundi 21 décembre, jour anniversaire des 40 ans, pour la diffusion du concert à la télévision et sur les réseaux sociaux de Mayotte La 1ère.</p>'
    .'<p>#40ansTVMayotte #ConcertDesGénérations</p>';

$confirmation = '<p><strong>Votre inscription au Concert des Générations est confirmée !</strong></p>'
    .'<p>Samedi 14 novembre 2026, dans les locaux de Mayotte La 1ère.<br />Accueil du public à partir de 18h30, début du concert à 20h15.</p>'
    .'<p><strong>Important : une pièce d\'identité au nom inscrit sur votre billet vous sera demandée à l\'entrée, pour chaque personne.</strong></p>'
    .'<p>Conservez votre billet et son QR code, il sera scanné à votre arrivée. Si vous ne pouvez plus venir, merci d\'annuler votre inscription pour libérer votre place.</p>'
    .'<p>À très bientôt pour fêter ensemble les 40 ans de la télévision à Mayotte !</p>';

$communes = ['Acoua', 'Bandraboua', 'Bandrélé', 'Bouéni', 'Chiconi', 'Chirongui', 'Dembeni', 'Dzaoudzi', 'Kani-Kéli', 'Koungou',
    'Mamoudzou', 'M\'Tsangamouji', 'Mtsamboro', 'Ouangani', 'Pamandzi', 'Sada', 'Tsingoni', 'Hors de Mayotte'];

$organizer = DB::table('organizers')->where('id', ORGANIZER_ID)->where('account_id', ACCOUNT_ID)->whereNull('deleted_at')->first();
if (!$organizer || !in_array($organizer->name, ['Mayotte la 1ere', 'Mayotte La 1ère'], true)) {
    throw new RuntimeException('Organisateur 8 inattendu : rien n\'est écrit');
}
if (DB::table('events')->where('organizer_id', ORGANIZER_ID)->where('title', TITRE)->whereNull('deleted_at')->exists()) {
    throw new RuntimeException('« '.TITRE.' » existe déjà : rien n\'est écrit');
}

auth()->setUser(\HiEvents\Models\User::findOrFail(USER_ID));

$eventId = DB::transaction(function () use ($organizer, $organisateur, $description, $confirmation, $communes) {
    app(EditOrganizerHandler::class)->handle(new EditOrganizerDTO(
        id: ORGANIZER_ID,
        name: 'Mayotte La 1ère',
        email: $organizer->email,
        account_id: ACCOUNT_ID,
        timezone: 'Indian/Mayotte',
        currency: 'EUR',
        phone: $organizer->phone,
        website: $organizer->website,
        description: $organisateur,
    ));

    $lieu = new AddressDTO(venue_name: 'Mayotte La 1ère', country: 'YT');

    $event = app(CreateEventHandler::class)->handle(new CreateEventDTO(
        title: TITRE,
        organizer_id: ORGANIZER_ID,
        account_id: ACCOUNT_ID,
        user_id: USER_ID,
        start_date: '2026-11-14 18:30:00',
        end_date: '2026-11-14 22:30:00',
        description: $description,
        timezone: 'Indian/Mayotte',
        currency: 'EUR',
        category: EventCategory::MUSIC,
        location_details: $lieu,
        status: EventStatus::LIVE->name,
    ));
    $id = $event->getId();

    app(PartialUpdateEventSettingsHandler::class)->handle(new PartialUpdateEventSettingsDTO(
        account_id: ACCOUNT_ID,
        event_id: $id,
        settings: [
            'location_details' => $lieu->toArray(),
            'is_online_event' => false,
            'attendee_details_collection_method' => 'PER_TICKET',
            'require_attendee_details' => true,
            'post_checkout_message' => $confirmation,
            'pass_platform_fee_to_buyer' => true,
        ],
    ));

    $categoryId = DB::table('product_categories')->where('event_id', $id)->whereNull('deleted_at')->orderBy('id')->value('id');
    if (!$categoryId) {
        throw new RuntimeException('Pas de catégorie de billets créée pour l\'événement '.$id);
    }
    $billet = app(CreateProductHandler::class)->handle(new UpsertProductDTO(
        account_id: ACCOUNT_ID,
        event_id: $id,
        product_category_id: $categoryId,
        title: 'Entrée gratuite sur inscription',
        type: ProductPriceType::FREE,
        product_type: ProductType::TICKET,
        prices: collect([new ProductPriceDTO(price: 0.00, initial_quantity_available: 300)]),
        price: 0.00,
        initial_quantity_available: 300,
        sale_start_date: '2026-10-26 00:00:00',
        sale_end_date: '2026-11-13 12:00:00',
        max_per_order: 2,
        description: 'Une place par personne, 2 maximum par inscription. Pièce d\'identité au nom inscrit demandée à l\'entrée.',
        min_per_order: 1,
        is_hidden: false,
        is_visible_at_box_office: true,
        is_sales_paused: false,
        waitlist_enabled: true,
    ));

    $question = fn (string $titre, QuestionTypeEnum $type, bool $obligatoire, ?array $options = null) => app(CreateQuestionHandler::class)->handle(new UpsertQuestionDTO(
        title: $titre,
        type: $type,
        required: $obligatoire,
        options: $options,
        event_id: $id,
        product_ids: [$billet->getId()],
        is_hidden: false,
        belongs_to: QuestionBelongsTo::ORDER,
    ));
    $question('Numéro de téléphone', QuestionTypeEnum::PHONE, true);
    $question('De quelle commune venez-vous ?', QuestionTypeEnum::DROPDOWN, true, $communes);
    $question('Vous venez en tant que', QuestionTypeEnum::DROPDOWN, true,
        ['Grand public', 'Artiste ou musicien', 'Acteur culturel ou associatif', 'Institutionnel ou élu', 'Presse ou influenceur']);
    $question('Pièce d\'identité', QuestionTypeEnum::CHECKBOX, true,
        ['Je présenterai une pièce d\'identité au nom de chaque personne inscrite']);
    $question('Informations', QuestionTypeEnum::CHECKBOX, false,
        ['J\'accepte de recevoir des informations de Mayotte La 1ère sur ses prochains événements']);

    return $id;
});

$organizer = DB::table('organizers')->where('id', ORGANIZER_ID)->first();
if ($organizer->status !== OrganizerStatus::LIVE->name) {
    app(UpdateOrganizerStatusHandler::class)->handle(new UpdateOrganizerStatusDTO(
        status: OrganizerStatus::LIVE->name,
        organizerId: ORGANIZER_ID,
        accountId: ACCOUNT_ID,
    ));
}

$e = DB::table('events')->where('id', $eventId)->first();
$s = DB::table('event_settings')->where('event_id', $eventId)->first();
$p = DB::table('products as p')->join('product_prices as pp', 'pp.product_id', '=', 'p.id')->where('p.event_id', $eventId)->first(['p.id', 'p.title', 'pp.initial_quantity_available', 'p.max_per_order', 'p.sale_start_date', 'p.sale_end_date']);
echo 'Organisateur 8 | '.DB::table('organizers')->where('id', ORGANIZER_ID)->value('name').' | '.DB::table('organizers')->where('id', ORGANIZER_ID)->value('status')."\n";
echo 'Événement '.$eventId.' | '.$e->title.' | '.$e->status.' | '.$e->start_date.' → '.$e->end_date.' UTC | '.$e->timezone."\n";
echo 'Billet '.$p->id.' | '.$p->title.' | '.$p->initial_quantity_available.' places | max '.$p->max_per_order.' | vente '.$p->sale_start_date.' → '.$p->sale_end_date.' UTC'."\n";
echo 'Réglages | fiche '.$s->attendee_details_collection_method.' | frais acheteur '.($s->pass_platform_fee_to_buyer ? 'oui' : 'non').' | questions '.DB::table('questions')->where('event_id', $eventId)->whereNull('deleted_at')->count()."\n";
