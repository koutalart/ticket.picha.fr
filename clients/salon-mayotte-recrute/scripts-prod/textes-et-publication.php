<?php
// Salon Mayotte Recrute (compte 7, organisateur 10, événement 13) : textes réécrits (un message pour les candidats,
// un pour les exposants), puis publication de l'organisateur et de l'événement. Relançable.
// ESSAI=1 : tout est annulé à la fin.

use HiEvents\DataTransferObjects\AddressDTO;
use HiEvents\DomainObjects\Enums\EventCategory;
use HiEvents\DomainObjects\Enums\ProductPriceType;
use HiEvents\DomainObjects\Enums\ProductType;
use HiEvents\Services\Application\Handlers\Event\DTO\UpdateEventDTO;
use HiEvents\Services\Application\Handlers\Event\DTO\UpdateEventStatusDTO;
use HiEvents\Services\Application\Handlers\Event\UpdateEventHandler;
use HiEvents\Services\Application\Handlers\Event\UpdateEventStatusHandler;
use HiEvents\Services\Application\Handlers\Organizer\DTO\EditOrganizerDTO;
use HiEvents\Services\Application\Handlers\Organizer\DTO\UpdateOrganizerStatusDTO;
use HiEvents\Services\Application\Handlers\Organizer\EditOrganizerHandler;
use HiEvents\Services\Application\Handlers\Organizer\UpdateOrganizerStatusHandler;
use HiEvents\Services\Application\Handlers\Product\DTO\UpsertProductDTO;
use HiEvents\Services\Application\Handlers\Product\EditProductHandler;
use HiEvents\Services\Application\Handlers\ProductCategory\DTO\UpsertProductCategoryDTO;
use HiEvents\Services\Application\Handlers\ProductCategory\EditProductCategoryHandler;
use HiEvents\Services\Domain\Product\DTO\ProductPriceDTO;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

const ACCOUNT_ID = 7;
const ORGANIZER_ID = 10;
const EVENT_ID = 13;
const USER_ID = 11;
const TZ = 'Indian/Mayotte';

$essai = getenv('ESSAI') === '1';

$organizer = DB::table('organizers')->where('id', ORGANIZER_ID)->where('account_id', ACCOUNT_ID)->whereNull('deleted_at')->first();
$event = DB::table('events')->where('id', EVENT_ID)->where('organizer_id', ORGANIZER_ID)->whereNull('deleted_at')->first();
if (!$organizer || $organizer->name !== 'Mayotte Recrute' || !$event || $event->title !== 'Salon Mayotte Recrute 2026') {
    throw new RuntimeException('Compte inattendu : rien n\'est modifié');
}
$categories = DB::table('product_categories')->where('event_id', EVENT_ID)->whereNull('deleted_at')->pluck('id', 'name');
$billets = DB::table('products')->where('event_id', EVENT_ID)->whereNull('deleted_at')->get()->keyBy('title');
foreach (['Candidats', 'Exposants'] as $c) {
    if (!isset($categories[$c])) throw new RuntimeException('Catégorie manquante : '.$c);
}
foreach (['Candidat sur place', 'Candidat en ligne', 'Exposant'] as $b) {
    if (!isset($billets[$b])) throw new RuntimeException('Billet manquant : '.$b);
}

auth()->setUser(\HiEvents\Models\User::findOrFail(USER_ID));

$organisateur = '<p>Chez Mayotte Recrute, on est convaincus que les talents de l\'île méritent d\'être vus.</p>'
    .'<p>Notre association organise le Salon de l\'emploi, des métiers et de l\'attractivité. Et toute l\'année, on aide les candidats à se préparer, on les met en relation avec les bons recruteurs, et on accompagne les employeurs pour recruter et garder leurs équipes.</p>'
    .'<p>Les offres du moment sont sur <a href="https://mayotterecrute.fr/">mayotterecrute.fr</a>.</p>';

$salon = '<p><strong>Le Salon Mayotte Recrute revient les 23 et 24 octobre, et cette année, il est tourné vers la reconstruction de l\'île.</strong></p>'
    .'<p>Deux jours au lycée des Lumières à Kawéni, avec tous ceux qui recrutent et qui forment à Mayotte. Et si vous ne pouvez pas venir, le salon est aussi en ligne.</p>'
    .'<h3>Vous cherchez un emploi, une formation, un nouveau départ ?</h3>'
    .'<p>Venez avec votre CV, ou même sans. 40 exposants vous attendent, et des conseillers relisent votre CV au bar à CV. Le vendredi après-midi, le job dating sans CV est fait pour ceux qui préfèrent parler plutôt qu\'écrire. Le samedi, on vous aide à préparer votre pitch, puis ce sont les entreprises qui viennent à vous lors du job dating inversé.</p>'
    .'<p>C\'est gratuit : inscrivez-vous comme candidat, sur place ou en ligne.</p>'
    .'<h3>Vous recrutez ?</h3>'
    .'<p>L\'an dernier, plus de 900 visiteurs sont venus et plus de 200 rencontres ont abouti. Cette année, on attend 500 personnes sur place et autant en ligne, des talents de l\'île comme de la diaspora.</p>'
    .'<p>Inscrivez votre entreprise ou votre organisme comme exposant : notre équipe vous rappelle pour organiser votre stand avec vous.</p>'
    .'<h3>Vendredi 23 octobre</h3>'
    .'<ul><li><strong>9h00</strong> Travailler pour l\'État, l\'école ou la commune sans concours, c\'est possible ?</li>'
    .'<li><strong>10h00</strong> Métiers d\'avenir ou filières bouchées : bien choisir sa formation</li>'
    .'<li><strong>11h00</strong> L\'emploi des personnes en situation de handicap, avec l\'AGEFIPH</li>'
    .'<li><strong>13h30</strong> Cérémonie d\'ouverture et visite des stands</li>'
    .'<li><strong>14h30</strong> Reconstruire Mayotte : les chantiers arrivent, les emplois aussi</li>'
    .'<li><strong>14h30 à 16h30</strong> Job dating sans CV avec France Travail</li>'
    .'<li><strong>15h45</strong> Entreprendre autrement pour répondre aux besoins de l\'île</li></ul>'
    .'<h3>Samedi 24 octobre</h3>'
    .'<ul><li><strong>9h00</strong> Soigner, accompagner, éduquer : les métiers qui font tenir l\'île debout</li>'
    .'<li><strong>9h00 à 12h00</strong> Atelier pitch : se présenter en deux minutes</li>'
    .'<li><strong>11h00</strong> J\'envoie des CV et personne ne répond : qu\'est-ce qui cloche ?</li>'
    .'<li><strong>13h30</strong> La place des femmes dans les métiers techniques</li>'
    .'<li><strong>13h30 à 15h00</strong> Job dating inversé avec M2E</li>'
    .'<li><strong>14h30</strong> Partir, revenir, réussir : en direct avec la diaspora</li></ul>'
    .'<p>Le programme complet, avec les ateliers et les intervenants, est sur <a href="https://salon.mayotterecrute.fr/programme/">salon.mayotterecrute.fr</a>.</p>'
    .'<h3>Bon à savoir</h3>'
    .'<p>Ouvert de 8h30 à 16h30 les deux jours. Parking gratuit et navettes, site accessible aux personnes à mobilité réduite. Toutes les conférences sont aussi diffusées en direct sur le salon en ligne.<br />'
    .'Une question ? Écrivez-nous à <a href="mailto:commercial@mayotterecrute.fr">commercial@mayotterecrute.fr</a>.</p>';

DB::beginTransaction();
try {
    app(EditOrganizerHandler::class)->handle(new EditOrganizerDTO(
        id: ORGANIZER_ID,
        name: 'Mayotte Recrute',
        email: $organizer->email,
        account_id: ACCOUNT_ID,
        timezone: TZ,
        currency: 'EUR',
        phone: $organizer->phone ?: null,
        website: 'https://salon.mayotterecrute.fr',
        description: $organisateur,
    ));

    app(UpdateEventHandler::class)->handle(new UpdateEventDTO(
        title: 'Salon Mayotte Recrute 2026',
        category: EventCategory::CONFERENCE,
        account_id: ACCOUNT_ID,
        id: EVENT_ID,
        start_date: '2026-10-23 08:30:00',
        end_date: '2026-10-24 16:30:00',
        description: $salon,
        timezone: TZ,
        location_details: new AddressDTO(
            venue_name: 'Gymnases du lycée des Lumières',
            address_line_1: 'Rue de la Geôle, Kawéni',
            city: 'Mamoudzou',
            zip_or_postal_code: '97600',
            country: 'YT',
        ),
    ));

    foreach ([
        'Candidats' => 'Vous cherchez un emploi, une formation ou une nouvelle voie ? C\'est par ici, et c\'est gratuit.',
        'Exposants' => 'Vous recrutez ou vous formez ? Réservez votre place parmi les exposants, on organise votre stand avec vous.',
    ] as $nom => $texte) {
        app(EditProductCategoryHandler::class)->handle(new UpsertProductCategoryDTO(
            name: $nom, description: $texte, is_hidden: false, event_id: EVENT_ID, product_category_id: $categories[$nom],
        ));
    }

    foreach ([
        'Candidat sur place' => [500, 1, '<p>On vous attend au lycée des Lumières à Kawéni, le vendredi 23 et le samedi 24 octobre, de 8h30 à 16h30. Venez quand vous voulez : une seule inscription vaut pour les deux jours.</p>'],
        'Candidat en ligne' => [null, 2, '<p>Pas besoin de vous déplacer. Dès le 21 octobre, visitez les stands, consultez les offres et suivez les conférences en direct, depuis chez vous.</p>'],
        'Exposant' => [null, 3, '<p>Dites-nous qui vous êtes et ce que vous proposez. Notre équipe vous rappelle rapidement pour finaliser votre stand, sur place, en ligne ou les deux.</p>'],
    ] as $titre => [$jauge, $ordre, $texte]) {
        $b = $billets[$titre];
        app(EditProductHandler::class)->handle(new UpsertProductDTO(
            account_id: ACCOUNT_ID,
            event_id: EVENT_ID,
            product_category_id: $b->product_category_id,
            title: $titre,
            type: ProductPriceType::FREE,
            product_type: ProductType::TICKET,
            prices: new Collection([new ProductPriceDTO(price: 0.00, initial_quantity_available: $jauge)]),
            price: 0.00,
            order: $ordre,
            initial_quantity_available: $jauge,
            max_per_order: 1,
            description: $texte,
            min_per_order: 1,
            product_id: $b->id,
        ));
    }

    app(UpdateOrganizerStatusHandler::class)->handle(new UpdateOrganizerStatusDTO(status: 'LIVE', organizerId: ORGANIZER_ID, accountId: ACCOUNT_ID));
    app(UpdateEventStatusHandler::class)->handle(new UpdateEventStatusDTO(status: 'LIVE', eventId: EVENT_ID, accountId: ACCOUNT_ID));

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

echo 'Organisateur : '.DB::table('organizers')->where('id', ORGANIZER_ID)->value('status')."\n";
echo 'Événement : '.DB::table('events')->where('id', EVENT_ID)->value('status')."\n";
echo 'Frais payés par l\'acheteur : '.(DB::table('event_settings')->where('event_id', EVENT_ID)->value('pass_platform_fee_to_buyer') ? 'oui' : 'NON')."\n";
