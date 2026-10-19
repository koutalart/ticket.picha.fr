<?php
// Innocent Event (compte 3, organisateur 4) : Halloween (événement 7).
// Description, couverture, image de partage et billet « Vente flash » à 15 € (100 places).
// Relançable : même description, mêmes images, le billet n'est créé qu'une fois.

use HiEvents\DomainObjects\Enums\ImageType;
use HiEvents\DomainObjects\Enums\ProductPriceType;
use HiEvents\DomainObjects\Enums\ProductType;
use HiEvents\Services\Application\Handlers\Images\CreateImageHandler;
use HiEvents\Services\Application\Handlers\Images\DTO\CreateImageDTO;
use HiEvents\Services\Application\Handlers\Product\CreateProductHandler;
use HiEvents\Services\Application\Handlers\Product\DTO\UpsertProductDTO;
use HiEvents\Services\Domain\Product\DTO\ProductPriceDTO;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

const EVENT_ID = 7;
const ORGANIZER_ID = 4;
const ACCOUNT_ID = 3;
const USER_ID = 6;
const CATEGORIE_BILLETS = 8;
const ENTREE_SIMPLE = 10;
const DOSSIER = '/tmp/innocent-halloween';
const TITRE_FLASH = '🔥 Vente flash - 15€ (limité)';

$p = '<p style="margin:0.5em 0px;">';
$description = $p.'<strong>Halloween au 5/5 avec Innocent Event 🎃</strong><br />'
    .'Le samedi 31 octobre, on sort les déguisements pour une nuit terrifiante : <strong>LE D SHOWCASE x JAH D ONE</strong>, avec DJ SWEETY, ASSKABOY et DJ BVNG\'Z aux platines 🔥</p>'
    .$p.'Afrobeats, amapiano, afro-caribéen, hip-hop, R&amp;B et kompa toute la nuit.</p>'
    .$p.'Vampires, zombies, sorcières, démons : viens déguisé(e), ramène ta team et sors ton costume le plus terrifiant 👻</p>'
    .$p.'🎬 Le D : <a href="https://www.youtube.com/watch?v=cEMFbokybco">LE D - 2K225 feat. BOLEMVN</a><br />'
    .'🎬 Jah D One : <a href="https://www.youtube.com/watch?v=vaxYr-eZmkw">Jah D One - wyfl riddim</a></p>'
    .$p.'<strong>Vente flash à 15 € avant le passage au tarif normal à 20 €. Prends ta place maintenant !</strong></p>';

$event = DB::table('events')->where('id', EVENT_ID)->where('organizer_id', ORGANIZER_ID)->where('account_id', ACCOUNT_ID)->whereNull('deleted_at')->first();
if (!$event || $event->title !== 'Halloween') {
    throw new RuntimeException('Événement inattendu : rien n\'est modifié');
}
$entree = DB::table('products')->where('id', ENTREE_SIMPLE)->where('event_id', EVENT_ID)->whereNull('deleted_at')->first();
if (!$entree || DB::table('product_categories')->where('id', CATEGORIE_BILLETS)->where('event_id', EVENT_ID)->doesntExist()) {
    throw new RuntimeException('Billets inattendus : rien n\'est modifié');
}
foreach (['halloween-couverture.jpg', 'halloween-partage.jpg'] as $fichier) {
    if (!is_readable(DOSSIER.'/'.$fichier)) {
        throw new RuntimeException('Fichier manquant : '.$fichier.' : rien n\'est modifié');
    }
}

auth()->setUser(\HiEvents\Models\User::findOrFail(USER_ID));

DB::table('events')->where('id', EVENT_ID)->update(['description' => $description, 'updated_at' => now()]);

foreach ([[ImageType::EVENT_COVER, 'halloween-couverture.jpg'], [ImageType::EVENT_SHARE_IMAGE, 'halloween-partage.jpg']] as [$type, $fichier]) {
    app(CreateImageHandler::class)->handle(new CreateImageDTO(
        userId: USER_ID,
        accountId: ACCOUNT_ID,
        image: new UploadedFile(DOSSIER.'/'.$fichier, $fichier, 'image/jpeg', null, true),
        imageType: $type,
        entityId: EVENT_ID,
    ));
}

if (DB::table('products')->where('event_id', EVENT_ID)->where('title', TITRE_FLASH)->whereNull('deleted_at')->doesntExist()) {
    DB::transaction(function () {
        app(CreateProductHandler::class)->handle(new UpsertProductDTO(
            account_id: ACCOUNT_ID,
            event_id: EVENT_ID,
            product_category_id: CATEGORIE_BILLETS,
            title: TITRE_FLASH,
            type: ProductPriceType::PAID,
            product_type: ProductType::TICKET,
            prices: collect([new ProductPriceDTO(price: 15.00, initial_quantity_available: 100)]),
            order: 1,
            max_per_order: 100,
            description: '<p style="margin:0.5em 0px;">Une fois épuisé, le tarif passe à 20 €</p>',
            min_per_order: 1,
        ));
        DB::table('products')->where('id', ENTREE_SIMPLE)->update(['order' => 2]);
    });
}

$e = DB::table('events')->where('id', EVENT_ID)->first();
echo 'Description : '.(str_contains($e->description, 'LE D SHOWCASE') ? 'à jour' : 'NON')."\n";
echo 'Images : '.DB::table('images')->where('entity_id', EVENT_ID)->where('entity_type', 'like', '%EventDomainObject')
    ->whereIn('type', ['EVENT_COVER', 'EVENT_SHARE_IMAGE'])->whereNull('deleted_at')->pluck('type')->sort()->implode(', ')."\n";
foreach (DB::table('products as p')->join('product_prices as pp', 'pp.product_id', '=', 'p.id')
    ->where('p.event_id', EVENT_ID)->whereNull('p.deleted_at')->orderBy('p.order')
    ->get(['p.order', 'p.title', 'p.is_hidden', 'pp.price', 'pp.initial_quantity_available']) as $r) {
    echo $r->order.' | '.$r->title.' | '.$r->price.' € | stock '.($r->initial_quantity_available ?? 'illimité').($r->is_hidden ? ' | masqué' : '')."\n";
}
echo 'Frais payés par l\'acheteur : '.(DB::table('event_settings')->where('event_id', EVENT_ID)->value('pass_platform_fee_to_buyer') ? 'oui' : 'NON')."\n";
