<?php
// Salon Mayotte Recrute (compte 7, organisateur 10, événement 13) : page organisateur et événement 2026.
// Passe par les mêmes traitements que l'interface. Relançable.
// ESSAI=1 : tout est annulé à la fin et aucune image n'est envoyée.

use HiEvents\DataTransferObjects\AddressDTO;
use HiEvents\DomainObjects\Enums\EventCategory;
use HiEvents\DomainObjects\Enums\ImageType;
use HiEvents\DomainObjects\Enums\ProductPriceType;
use HiEvents\DomainObjects\Enums\ProductType;
use HiEvents\DomainObjects\Enums\QuestionBelongsTo;
use HiEvents\DomainObjects\Enums\QuestionTypeEnum;
use HiEvents\Services\Application\Handlers\Event\CoverVideo\DTO\UploadEventCoverVideoDTO;
use HiEvents\Services\Application\Handlers\Event\CoverVideo\UploadEventCoverVideoHandler;
use HiEvents\Services\Application\Handlers\Event\DTO\UpdateEventDTO;
use HiEvents\Services\Application\Handlers\Event\UpdateEventHandler;
use HiEvents\Services\Application\Handlers\EventSettings\DTO\PartialUpdateEventSettingsDTO;
use HiEvents\Services\Application\Handlers\EventSettings\PartialUpdateEventSettingsHandler;
use HiEvents\Services\Application\Handlers\Images\CreateImageHandler;
use HiEvents\Services\Application\Handlers\Images\DTO\CreateImageDTO;
use HiEvents\Services\Application\Handlers\Organizer\DTO\EditOrganizerDTO;
use HiEvents\Services\Application\Handlers\Organizer\DTO\PartialUpdateOrganizerSettingsDTO;
use HiEvents\Services\Application\Handlers\Organizer\EditOrganizerHandler;
use HiEvents\Services\Application\Handlers\Organizer\Settings\PartialUpdateOrganizerSettingsHandler;
use HiEvents\Services\Application\Handlers\Product\CreateProductHandler;
use HiEvents\Services\Application\Handlers\Product\DTO\UpsertProductDTO;
use HiEvents\Services\Application\Handlers\Product\EditProductHandler;
use HiEvents\Services\Application\Handlers\ProductCategory\CreateProductCategoryHandler;
use HiEvents\Services\Application\Handlers\ProductCategory\DTO\UpsertProductCategoryDTO;
use HiEvents\Services\Application\Handlers\ProductCategory\EditProductCategoryHandler;
use HiEvents\Services\Application\Handlers\Question\CreateQuestionHandler;
use HiEvents\Services\Application\Handlers\Question\DTO\UpsertQuestionDTO;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

const ACCOUNT_ID = 7;
const ORGANIZER_ID = 10;
const EVENT_ID = 13;
const USER_ID = 11;
const PRODUCT_ID = 19;
const CATEGORY_ID = 15;
const DOSSIER = '/tmp/salon-mr';
const TZ = 'Indian/Mayotte';
const SECTEURS = ['IT, Numérique et Tech', 'Commerce, Vente et Marketing', 'Ingénierie et Industrie', 'BTP et Construction', 'Administration, RH et Finance', 'Santé et Social', 'Éducation et Formation', 'Service public', 'Autre'];

$essai = getenv('ESSAI') === '1';

$organizer = DB::table('organizers')->where('id', ORGANIZER_ID)->where('account_id', ACCOUNT_ID)->whereNull('deleted_at')->first();
$event = DB::table('events')->where('id', EVENT_ID)->where('organizer_id', ORGANIZER_ID)->whereNull('deleted_at')->first();
$user = DB::table('users')->where('id', USER_ID)->first();
if (!$organizer || !$event || !$user || $user->email !== 'mourchid@mayotterecrute.fr'
    || !in_array($event->title, ['Salon de l\'Emploi - Mayotte Recrute', 'Salon Mayotte Recrute 2026'], true)) {
    throw new RuntimeException('Compte inattendu : rien n\'est modifié');
}
if (DB::table('orders')->where('event_id', EVENT_ID)->exists()) {
    throw new RuntimeException('L\'événement a déjà des inscriptions : à revoir avant de modifier les entrées');
}
$images = ['logo-mayotte-recrute.png', 'couverture-organisateur.jpg', 'salon-mayotte-recrute-2026.jpg', 'salon-mayotte-recrute-couverture.mp4'];
if (!$essai) {
    foreach ($images as $f) {
        if (!is_readable(DOSSIER.'/'.$f)) {
            throw new RuntimeException('Fichier manquant : '.$f.' : rien n\'est modifié');
        }
    }
}

auth()->setUser(\HiEvents\Models\User::findOrFail(USER_ID));

$theme = ['mode' => 'dark', 'accent' => '#F6C31D', 'background' => '#2E3772', 'background_type' => 'COLOR', 'font_family' => 'Montserrat'];
$adresse = new AddressDTO(
    venue_name: 'Gymnases du lycée des Lumières',
    address_line_1: 'Rue de la Geôle, Kawéni',
    city: 'Mamoudzou',
    zip_or_postal_code: '97600',
    country: 'YT',
);

$descriptionOrganisateur = '<p><strong>Mayotte Recrute</strong> est l\'association organisatrice du Salon de l\'emploi, des métiers et de l\'attractivité de Mayotte, engagée pour l\'insertion professionnelle.</p>'
    .'<p>Notre mission : rapprocher les talents et les employeurs du territoire. Nous préparons les candidats en développant leur employabilité, nous les connectons aux recruteurs par une mise en relation ciblée, et nous outillons les employeurs pour recruter et fidéliser durablement.</p>'
    .'<p>Retrouvez toutes nos offres d\'emploi sur <a href="https://mayotterecrute.fr/">mayotterecrute.fr</a>.</p>';

$descriptionSalon = '<p><strong>Le rendez-vous de l\'emploi à Mayotte revient pour une 2e édition dédiée à la reconstruction de l\'île.</strong><br />'
    .'Deux jours pour rencontrer ceux qui recrutent et ceux qui forment : demandeurs d\'emploi, jeunes diplômés, personnes en reconversion ou simplement à l\'écoute d\'une opportunité, ce salon est fait pour vous.</p>'
    .'<p>Vendredi 23 et samedi 24 octobre 2026, de 8h30 à 16h30, aux gymnases du lycée des Lumières à Kawéni (Mamoudzou), et en ligne sur salon.mayotterecrute.fr.</p>'
    .'<h3>Au programme</h3>'
    .'<ul><li>40 exposants : employeurs, institutions et organismes de formation</li>'
    .'<li>10 conférences, diffusées en direct sur le salon en ligne</li>'
    .'<li>Ateliers, bar à CV, job dating sans CV et job dating inversé</li>'
    .'<li>Espace entretiens individuels avec les recruteurs, les deux jours</li>'
    .'<li>Salon en ligne dès le 21 octobre : stands, offres d\'emploi et conférences en direct</li></ul>'
    .'<h3>Vendredi 23 octobre</h3>'
    .'<ul><li><strong>9h00</strong> Conférence : Titulaire, contractuel, apprenti, les clés pour une carrière au service de l\'État et des collectivités</li>'
    .'<li><strong>9h00 à 12h00</strong> Bar à CV avec l\'ANAF et France Travail</li>'
    .'<li><strong>10h00</strong> Conférence : Métiers d\'avenir ou filières bouchées, bien choisir sa formation</li>'
    .'<li><strong>11h00</strong> Conférence : L\'AGEFIPH à Mayotte, les solutions pour l\'emploi des personnes en situation de handicap</li>'
    .'<li><strong>12h00</strong> Zoom Métiers n°1 : un métier en 10 minutes</li>'
    .'<li><strong>13h30</strong> Cérémonie d\'ouverture officielle et visite des stands</li>'
    .'<li><strong>14h30</strong> Temps fort : Reconstruire Mayotte, les chantiers arrivent, les emplois aussi</li>'
    .'<li><strong>14h30 à 16h30</strong> Job dating sans CV avec France Travail</li>'
    .'<li><strong>15h45</strong> Conférence : Entreprendre autrement, innover pour répondre aux besoins de Mayotte</li></ul>'
    .'<h3>Samedi 24 octobre</h3>'
    .'<ul><li><strong>9h00</strong> Conférence : Soigner, accompagner, éduquer, les métiers qui font tenir l\'île debout</li>'
    .'<li><strong>9h00 à 12h00</strong> Atelier pitch : se présenter en deux minutes face à un recruteur</li>'
    .'<li><strong>10h00</strong> Conférence : Face aux compétences qui manquent, former ses collaborateurs</li>'
    .'<li><strong>11h00</strong> Conférence : Décrocher un emploi en 2026, ce que les recruteurs attendent</li>'
    .'<li><strong>12h00</strong> Zoom Métiers n°2</li>'
    .'<li><strong>13h30</strong> Conférence : La place des femmes dans les métiers techniques</li>'
    .'<li><strong>13h30 à 15h00</strong> Job dating inversé avec M2E : les candidats pitchent, les entreprises viennent à eux</li>'
    .'<li><strong>14h30</strong> Conférence : Partir, revenir, réussir, les cadres dont Mayotte a besoin (en direct avec la diaspora)</li></ul>'
    .'<p>Programme détaillé et susceptible d\'évoluer : <a href="https://salon.mayotterecrute.fr/programme/">salon.mayotterecrute.fr/programme</a></p>'
    .'<h3>Infos pratiques</h3>'
    .'<p>Entrée libre et gratuite, sur pré-inscription. Parking gratuit et navettes. Site accessible aux personnes à mobilité réduite.<br />'
    .'Vous êtes une entreprise et souhaitez exposer ? Écrivez à <a href="mailto:commercial@mayotterecrute.fr">commercial@mayotterecrute.fr</a>.</p>'
    .'<p><strong>Inscrivez-vous gratuitement dès maintenant, sur place ou en ligne.</strong></p>';

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
        description: $descriptionOrganisateur,
    ));

    app(PartialUpdateOrganizerSettingsHandler::class)->handle(PartialUpdateOrganizerSettingsDTO::from([
        'organizerId' => ORGANIZER_ID,
        'accountId' => (string) ACCOUNT_ID,
        'facebook_handle' => 'mayotterecrute.fr',
        'linkedin_handle' => 'mayotterecrute',
        'twitter_handle' => 'mayotterecrute',
        'website_url' => 'https://salon.mayotterecrute.fr',
        'location_details' => $adresse->toArray(),
        'homepage_theme_settings' => $theme,
        'seo_title' => 'Mayotte Recrute, salon de l\'emploi à Mayotte',
        'seo_description' => 'Mayotte Recrute organise le Salon de l\'emploi, des métiers et de l\'attractivité de Mayotte. Inscription gratuite, sur place à Kawéni ou en ligne.',
        'default_pass_platform_fee_to_buyer' => true,
    ]));

    app(UpdateEventHandler::class)->handle(new UpdateEventDTO(
        title: 'Salon Mayotte Recrute 2026',
        category: EventCategory::CONFERENCE,
        account_id: ACCOUNT_ID,
        id: EVENT_ID,
        start_date: '2026-10-23 08:30:00',
        end_date: '2026-10-24 16:30:00',
        description: $descriptionSalon,
        timezone: TZ,
        location_details: $adresse,
    ));

    app(PartialUpdateEventSettingsHandler::class)->handle(new PartialUpdateEventSettingsDTO(
        account_id: ACCOUNT_ID,
        event_id: EVENT_ID,
        settings: [
            'homepage_theme_settings' => $theme,
            'seo_title' => 'Salon Mayotte Recrute 2026, 23 et 24 octobre à Kawéni',
            'seo_description' => 'Salon de l\'emploi, des métiers et de l\'attractivité de Mayotte : 40 exposants, 10 conférences, job datings. Entrée gratuite sur pré-inscription, sur place ou en ligne.',
            'website_url' => 'https://salon.mayotterecrute.fr',
            'pass_platform_fee_to_buyer' => true,
        ],
    ));

    app(EditProductCategoryHandler::class)->handle(new UpsertProductCategoryDTO(
        name: 'Candidats',
        description: 'Demandeurs d\'emploi, jeunes diplômés, personnes en reconversion : inscription gratuite.',
        is_hidden: false,
        event_id: EVENT_ID,
        product_category_id: CATEGORY_ID,
    ));
    $categorieExposants = DB::table('product_categories')->where('event_id', EVENT_ID)->where('name', 'Exposants')->whereNull('deleted_at')->value('id')
        ?? app(CreateProductCategoryHandler::class)->handle(new UpsertProductCategoryDTO(
            name: 'Exposants',
            description: 'Entreprises, institutions et organismes de formation : demande de participation au salon.',
            is_hidden: false,
            event_id: EVENT_ID,
        ))->getId();

    $entree = fn (string $titre, int $categorie, ?int $jauge, string $texte, int $ordre, ?int $id = null) => new UpsertProductDTO(
        account_id: ACCOUNT_ID,
        event_id: EVENT_ID,
        product_category_id: $categorie,
        title: $titre,
        type: ProductPriceType::FREE,
        product_type: ProductType::TICKET,
        price: 0.00,
        order: $ordre,
        initial_quantity_available: $jauge,
        max_per_order: 1,
        description: $texte,
        min_per_order: 1,
        product_id: $id,
    );
    $billet = function (string $titre, int $categorie, ?int $jauge, string $texte, int $ordre) use ($entree): int {
        $id = DB::table('products')->where('event_id', EVENT_ID)->where('title', $titre)->whereNull('deleted_at')->value('id');
        if ($id) {
            app(EditProductHandler::class)->handle($entree($titre, $categorie, $jauge, $texte, $ordre, $id));
            return $id;
        }
        return app(CreateProductHandler::class)->handle($entree($titre, $categorie, $jauge, $texte, $ordre))->getId();
    };

    app(EditProductHandler::class)->handle($entree('Candidat sur place', CATEGORY_ID, 500, '<p>Accès aux gymnases du lycée des Lumières à Kawéni, le vendredi 23 et le samedi 24 octobre de 8h30 à 16h30.</p>', 1, PRODUCT_ID));
    $surPlace = PRODUCT_ID;
    $enLigne = $billet('Candidat en ligne', CATEGORY_ID, null, '<p>Accès au salon virtuel dès le 21 octobre : stands, offres d\'emploi et conférences en direct sur salon.mayotterecrute.fr.</p>', 2);
    $exposant = $billet('Exposant', $categorieExposants, null, '<p>Demande de participation pour votre entreprise ou organisme, sur site et ou en ligne. L\'équipe Mayotte Recrute vous recontacte pour finaliser votre stand.</p>', 3);

    $candidats = [$surPlace, $enLigne];
    $questions = [
        ['Téléphone', QuestionTypeEnum::PHONE, true, null, $candidats],
        ['Secteur d\'activité principal', QuestionTypeEnum::DROPDOWN, false, SECTEURS, $candidats],
        ['Entreprise ou organisme', QuestionTypeEnum::SINGLE_LINE_TEXT, true, null, [$exposant]],
        ['Fonction du contact', QuestionTypeEnum::SINGLE_LINE_TEXT, true, null, [$exposant]],
        ['Téléphone du contact', QuestionTypeEnum::PHONE, true, null, [$exposant]],
        ['Secteur d\'activité de l\'entreprise', QuestionTypeEnum::DROPDOWN, true, SECTEURS, [$exposant]],
        ['Mode de participation', QuestionTypeEnum::RADIO, true, ['Sur site', 'En ligne', 'Sur site et en ligne'], [$exposant]],
        ['Nombre de recruteurs présents', QuestionTypeEnum::SINGLE_LINE_TEXT, false, null, [$exposant]],
        ['Postes ou métiers proposés', QuestionTypeEnum::MULTI_LINE_TEXT, false, null, [$exposant]],
        ['Droit à l\'image', QuestionTypeEnum::CHECKBOX, true,
            ['Je comprends qu\'en participant à cet événement, je suis susceptible d\'être filmé ou photographié, et que ces images pourront être utilisées pour la promotion du salon.'],
            [$surPlace, $enLigne, $exposant]],
    ];
    foreach ($questions as [$titre, $type, $obligatoire, $options, $produits]) {
        if (DB::table('questions')->where('event_id', EVENT_ID)->where('title', $titre)->whereNull('deleted_at')->exists()) {
            continue;
        }
        app(CreateQuestionHandler::class)->handle(new UpsertQuestionDTO(
            title: $titre,
            type: $type,
            required: $obligatoire,
            options: $options,
            event_id: EVENT_ID,
            product_ids: $produits,
            is_hidden: false,
            belongs_to: QuestionBelongsTo::PRODUCT,
        ));
    }

    DB::table('users')->where('id', USER_ID)->whereNull('email_verified_at')->update(['email_verified_at' => now()]);
    DB::table('accounts')->where('id', ACCOUNT_ID)->update([
        'account_verified_at' => DB::raw('coalesce(account_verified_at, now())'),
        'is_manually_verified' => true,
    ]);

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

foreach ([
    [ImageType::ORGANIZER_LOGO, ORGANIZER_ID, 'logo-mayotte-recrute.png', 'image/png'],
    [ImageType::ORGANIZER_COVER, ORGANIZER_ID, 'couverture-organisateur.jpg', 'image/jpeg'],
    [ImageType::EVENT_COVER, EVENT_ID, 'salon-mayotte-recrute-2026.jpg', 'image/jpeg'],
    [ImageType::EVENT_SHARE_IMAGE, EVENT_ID, 'salon-mayotte-recrute-2026.jpg', 'image/jpeg'],
] as [$type, $entite, $fichier, $mime]) {
    app(CreateImageHandler::class)->handle(new CreateImageDTO(
        userId: USER_ID,
        accountId: ACCOUNT_ID,
        image: new UploadedFile(DOSSIER.'/'.$fichier, $fichier, $mime, null, true),
        imageType: $type,
        entityId: $entite,
    ));
}

app(UploadEventCoverVideoHandler::class)->handle(new UploadEventCoverVideoDTO(
    eventId: EVENT_ID,
    accountId: ACCOUNT_ID,
    video: new UploadedFile(DOSSIER.'/salon-mayotte-recrute-couverture.mp4', 'salon-mayotte-recrute-couverture.mp4', 'video/mp4', null, true),
));

$o = DB::table('organizers')->where('id', ORGANIZER_ID)->first(['name', 'status']);
$e = DB::table('events')->where('id', EVENT_ID)->first(['title', 'status', 'start_date', 'end_date']);
echo 'Organisateur : '.$o->name.' ('.$o->status.")\n";
echo 'Événement : '.$e->title.' ('.$e->status.') du '.$e->start_date.' au '.$e->end_date." (UTC)\n";
echo 'Catégories : '.DB::table('product_categories')->where('event_id', EVENT_ID)->whereNull('deleted_at')->pluck('name')->implode(', ')."\n";
echo 'Billets : '.DB::table('products')->where('event_id', EVENT_ID)->whereNull('deleted_at')->pluck('title')->implode(', ')."\n";
echo 'Questions : '.DB::table('questions')->where('event_id', EVENT_ID)->whereNull('deleted_at')->pluck('title')->implode(', ')."\n";
echo 'Images : '.DB::table('images')->whereNull('deleted_at')->where(fn ($q) => $q->where(fn ($q) => $q->where('entity_type', 'like', '%Organizer%')->where('entity_id', ORGANIZER_ID))->orWhere(fn ($q) => $q->where('entity_type', 'like', '%Event%')->where('entity_id', EVENT_ID)))->pluck('type')->sort()->implode(', ')."\n";
echo 'Compte vérifié : '.(DB::table('accounts')->where('id', ACCOUNT_ID)->value('is_manually_verified') ? 'oui' : 'non')."\n";
