<?php
// Marche des Amis, 10e édition (organisateur 14, compte 3) : événement en brouillon, inscription 15 €, option sérénité 0,49 €/commande,
// couverture + image de partage, nom par participant, message après achat. Rien n'est créé si un contrôle échoue ou si l'événement existe déjà.

use HiEvents\DataTransferObjects\AddressDTO;
use HiEvents\DomainObjects\Enums\EventCategory;
use HiEvents\DomainObjects\Enums\ImageType;
use HiEvents\DomainObjects\Enums\ProductPriceType;
use HiEvents\DomainObjects\Enums\ProductType;
use HiEvents\DomainObjects\Status\EventStatus;
use HiEvents\Http\Request\EventSettings\UpdateEventSettingsRequest;
use HiEvents\Services\Application\Handlers\Event\CreateEventHandler;
use HiEvents\Services\Application\Handlers\Event\DTO\CreateEventDTO;
use HiEvents\Services\Application\Handlers\EventSettings\DTO\PartialUpdateEventSettingsDTO;
use HiEvents\Services\Application\Handlers\EventSettings\PartialUpdateEventSettingsHandler;
use HiEvents\Services\Application\Handlers\Images\CreateImageHandler;
use HiEvents\Services\Application\Handlers\Images\DTO\CreateImageDTO;
use HiEvents\Services\Application\Handlers\Product\CreateProductHandler;
use HiEvents\Services\Application\Handlers\Product\DTO\UpsertProductDTO;
use HiEvents\Services\Application\Handlers\ProductCategory\CreateProductCategoryHandler;
use HiEvents\Services\Application\Handlers\ProductCategory\DTO\UpsertProductCategoryDTO;
use HiEvents\Services\Domain\Product\DTO\ProductPriceDTO;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

const ACCOUNT_ID = 3;
const ORGANIZER_ID = 14;
const USER_ID = 6;
const TITRE = 'Marche des Amis, 10e édition';
const COUVERTURE = '/tmp/arrm-couverture.png';
const AFFICHE = '/tmp/arrm-affiche.jpg';

$org = DB::table('organizers')->where('id', ORGANIZER_ID)->where('account_id', ACCOUNT_ID)->whereNull('deleted_at')->first();
if (!$org || $org->name !== 'Amis Raid Rando de Mayotte') {
    throw new RuntimeException('Organisateur 14 inattendu : rien n\'est créé');
}
if (DB::table('events')->where('organizer_id', ORGANIZER_ID)->where('title', TITRE)->whereNull('deleted_at')->exists()) {
    throw new RuntimeException('« '.TITRE.' » existe déjà : rien n\'est créé');
}
foreach ([COUVERTURE, AFFICHE] as $f) {
    if (!is_readable($f)) {
        throw new RuntimeException($f.' absent : rien n\'est créé');
    }
}

$description = '<p><strong>Pour sa 10e édition, la Marche des Amis revient le dimanche 29 novembre 2026 à 7h00 !</strong></p>'
    .'<p>13 km au départ de la place de l\'ancien marché, à Mamoudzou. Pas besoin d\'être un grand sportif ni d\'avoir un niveau particulier : la marche est ouverte à tous. '
    .'En famille, entre amis ou en solo, venez partager un moment de convivialité, découvrir les paysages de Mayotte et prendre soin de votre santé. 💚</p>'
    .'<p>👕 L\'inscription à 15 € comprend le maillot collector, en édition limitée, de cette 10e édition.</p>'
    .'<p><strong>Retrait des tee-shirts et des dossards : samedi 28 novembre au 5/5, de 10h00 à 18h00.</strong></p>'
    .'<p>Le sport se partage, la santé se préserve !</p>';

$reglages = [
    'attendee_details_collection_method' => 'PER_TICKET',
    'pass_platform_fee_to_buyer' => true,
    'post_checkout_message' => '<p><strong>Merci pour votre inscription à la Marche des Amis !</strong></p>'
        .'<p>Retrait des tee-shirts et des dossards : samedi 28 novembre au 5/5, de 10h00 à 18h00.<br />'
        .'Départ dimanche 29 novembre à 7h00, place de l\'ancien marché, Mamoudzou.</p>',
    'location_details' => [
        'venue_name' => 'Place de l\'ancien marché',
        'address_line_1' => 'Place de l\'ancien marché',
        'city' => 'Mamoudzou',
        'zip_or_postal_code' => '97600',
        'country' => 'YT',
    ],
];
$regles = array_filter((new UpdateEventSettingsRequest())->rules(),
    fn ($cle) => in_array(explode('.', $cle)[0], array_keys($reglages), true), ARRAY_FILTER_USE_KEY);
Validator::make($reglages, $regles)->validate();

auth()->setUser(\HiEvents\Models\User::findOrFail(USER_ID));

$event = DB::transaction(function () use ($description, $reglages) {
    $event = app(CreateEventHandler::class)->handle(new CreateEventDTO(
        title: TITRE,
        organizer_id: ORGANIZER_ID,
        account_id: ACCOUNT_ID,
        user_id: USER_ID,
        start_date: '2026-11-29 07:00:00',
        end_date: '2026-11-29 12:00:00',
        description: $description,
        timezone: 'Indian/Mayotte',
        currency: 'EUR',
        category: EventCategory::SPORTS,
        location_details: new AddressDTO(
            venue_name: 'Place de l\'ancien marché',
            address_line_1: 'Place de l\'ancien marché',
            city: 'Mamoudzou',
            zip_or_postal_code: '97600',
            country: 'YT',
        ),
        status: EventStatus::DRAFT->name,
    ));
    $eventId = $event->getId();

    app(PartialUpdateEventSettingsHandler::class)->handle(new PartialUpdateEventSettingsDTO(
        account_id: ACCOUNT_ID,
        event_id: $eventId,
        settings: $reglages,
    ));

    $categorieBillets = DB::table('product_categories')->where('event_id', $eventId)->whereNull('deleted_at')->orderBy('id')->value('id');
    $categorieOptions = app(CreateProductCategoryHandler::class)->handle(new UpsertProductCategoryDTO(
        name: 'Options & services',
        description: null,
        is_hidden: false,
        event_id: $eventId,
    ))->getId();

    app(CreateProductHandler::class)->handle(new UpsertProductDTO(
        account_id: ACCOUNT_ID,
        event_id: $eventId,
        product_category_id: $categorieBillets,
        title: 'Inscription + tee-shirt collector 10e édition',
        type: ProductPriceType::PAID,
        product_type: ProductType::TICKET,
        prices: collect([new ProductPriceDTO(price: 15.00)]),
        price: 15.00,
        order: 1,
        max_per_order: 20,
        min_per_order: 1,
        description: '<p>Inscription à la marche (13 km) avec le tee-shirt collector de la 10e édition, taille unique.</p>',
    ));

    app(CreateProductHandler::class)->handle(new UpsertProductDTO(
        account_id: ACCOUNT_ID,
        event_id: $eventId,
        product_category_id: $categorieOptions,
        title: '🛡️ Option sérénité / commande',
        type: ProductPriceType::PAID,
        product_type: ProductType::GENERAL,
        prices: collect([new ProductPriceDTO(price: 0.49)]),
        price: 0.49,
        order: 2,
        max_per_order: 1,
        min_per_order: 1,
        description: '<p style="margin:0.5em 0px;">Pour seulement 0,49 € par commande, bénéficie des conditions d’annulation et de remboursement prévues pour cet événement</p>',
    ));

    return $event;
});

$eventId = $event->getId();
foreach ([[ImageType::EVENT_COVER, COUVERTURE, 'image/png'], [ImageType::EVENT_SHARE_IMAGE, AFFICHE, 'image/jpeg']] as [$type, $fichier, $mime]) {
    $copie = sys_get_temp_dir().'/arrm-'.$type->name.'-'.basename($fichier);
    copy($fichier, $copie);
    app(CreateImageHandler::class)->handle(new CreateImageDTO(
        userId: USER_ID,
        accountId: ACCOUNT_ID,
        image: new UploadedFile($copie, basename($fichier), $mime, null, true),
        imageType: $type,
        entityId: $eventId,
    ));
}

$e = DB::table('events')->where('id', $eventId)->first();
$s = DB::table('event_settings')->where('event_id', $eventId)->first();
echo "Événement {$e->id} | {$e->title} | {$e->status} | {$e->start_date} → {$e->end_date} UTC | short_id {$e->short_id}\n";
echo 'Frais acheteur '.var_export((bool) $s->pass_platform_fee_to_buyer, true).' | infos '.$s->attendee_details_collection_method."\n";
foreach (DB::table('products')->where('event_id', $eventId)->whereNull('deleted_at')->orderBy('order')->get() as $p) {
    $prix = DB::table('product_prices')->where('product_id', $p->id)->value('price');
    echo "Produit {$p->id} | {$p->title} | {$prix} € | min {$p->min_per_order} max {$p->max_per_order} | stock ".($p->initial_quantity_available ?? 'illimité')."\n";
}
echo 'Images | '.DB::table('images')->where('entity_id', $eventId)->where('entity_type', 'like', '%Event%')->whereNull('deleted_at')->pluck('type')->implode(', ')."\n";
