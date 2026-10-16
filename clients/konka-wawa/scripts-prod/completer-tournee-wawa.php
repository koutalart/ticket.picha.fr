<?php
// Konka Wawa (organisateur 9, compte 6) : tournée « Vaha la chaîne » complétée d'après les affiches.
// - crée la date de Lyon (ven. 27 nov.) si elle n'existe pas ;
// - pour les 5 dates : 21 h → 5 h, salle et adresse, description, billet « Entrée » 35 € sans limite (en ligne) ;
// - affiche principale = le flyer de la ville (déposé dans /tmp/wawa-affiches/ du conteneur).
// Les écritures en base sont dans une seule transaction ; les affiches sont envoyées ensuite.

use HiEvents\DataTransferObjects\AddressDTO;
use HiEvents\DomainObjects\Enums\EventCategory;
use HiEvents\DomainObjects\Enums\ImageType;
use HiEvents\DomainObjects\Enums\ProductPriceType;
use HiEvents\DomainObjects\Enums\ProductType;
use HiEvents\DomainObjects\Status\EventStatus;
use HiEvents\Services\Application\Handlers\Event\CreateEventHandler;
use HiEvents\Services\Application\Handlers\Event\DTO\CreateEventDTO;
use HiEvents\Services\Application\Handlers\Event\DTO\UpdateEventDTO;
use HiEvents\Services\Application\Handlers\Event\UpdateEventHandler;
use HiEvents\Services\Application\Handlers\EventSettings\DTO\PartialUpdateEventSettingsDTO;
use HiEvents\Services\Application\Handlers\EventSettings\PartialUpdateEventSettingsHandler;
use HiEvents\Services\Application\Handlers\Images\CreateImageHandler;
use HiEvents\Services\Application\Handlers\Images\DTO\CreateImageDTO;
use HiEvents\Services\Application\Handlers\Product\CreateProductHandler;
use HiEvents\Services\Application\Handlers\Product\DTO\UpsertProductDTO;
use HiEvents\Services\Domain\Product\DTO\ProductPriceDTO;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

const ORGANIZER_ID = 9;
const ACCOUNT_ID = 6;
const USER_ID = 10;
const AFFICHES = '/tmp/wawa-affiches';

$dates = [
    ['id' => 8, 'ville' => 'Marseille', 'jour' => 'samedi 7 novembre', 'debut' => '2026-11-07 21:00:00', 'fin' => '2026-11-08 05:00:00',
        'salle' => 'Florida Palace', 'adresse' => '162-164 boulevard Mireille Lauze', 'cp' => '13010', 'commune' => 'Marseille', 'affiche' => 'wawa-marseille.jpg'],
    ['id' => 9, 'ville' => 'Colmar', 'jour' => 'samedi 14 novembre', 'debut' => '2026-11-14 21:00:00', 'fin' => '2026-11-15 05:00:00',
        'salle' => 'Salle polyvalente', 'adresse' => '1A rue du Vignoble', 'cp' => '67210', 'commune' => 'Goxwiller', 'affiche' => 'wawa-colmar.jpg'],
    ['id' => 10, 'ville' => 'Toulouse', 'jour' => 'samedi 21 novembre', 'debut' => '2026-11-21 21:00:00', 'fin' => '2026-11-22 05:00:00',
        'salle' => 'Salle Amira', 'adresse' => '9 rue Jean Moulin', 'cp' => '31830', 'commune' => 'Plaisance-du-Touch', 'affiche' => 'wawa-toulouse.jpg'],
    ['id' => null, 'ville' => 'Lyon', 'jour' => 'vendredi 27 novembre', 'debut' => '2026-11-27 21:00:00', 'fin' => '2026-11-28 05:00:00',
        'salle' => 'Le Gold Réception', 'adresse' => '7 route des Troques', 'cp' => '69630', 'commune' => 'Chaponost', 'affiche' => 'wawa-lyon.jpg'],
    ['id' => 11, 'ville' => 'Limoges', 'jour' => 'samedi 28 novembre', 'debut' => '2026-11-28 21:00:00', 'fin' => '2026-11-29 05:00:00',
        'salle' => 'Pavillon de Buxerolles', 'adresse' => '20 rue Arthur Groussier', 'cp' => '87100', 'commune' => 'Limoges', 'affiche' => 'wawa-limoges.jpg'],
];

$titre = fn (array $d) => 'Wawa – 25 ans de scène · '.$d['ville'];

$description = fn (array $d) => '<p><strong>Wawa fête ses 25 ans de scène au '.e($d['salle']).' !</strong><br />'
    .'« Vaha la chaîne », « Fondy eh! » et tous les tubes qui font danser Madagascar depuis 25 ans : '
    .'Wawa pose ses valises à '.e($d['ville']).' pour une grande soirée salegy. 🔥</p>'
    .'<p>Viens chanter, danser et célébrer avec toute la communauté malgache. Rendez-vous le '.e($d['jour']).' à partir de 21 h, '
    .'au '.e($d['adresse']).', '.e($d['cp']).' '.e($d['commune']).'.</p>'
    .'<p><strong>Entrée 35 € : les places partent vite, réserve la tienne dès maintenant !</strong></p>';

$adresse = fn (array $d) => new AddressDTO(
    venue_name: $d['salle'],
    address_line_1: $d['adresse'],
    city: $d['commune'],
    zip_or_postal_code: $d['cp'],
    country: 'FR',
);

$organizer = DB::table('organizers')->where('id', ORGANIZER_ID)->where('account_id', ACCOUNT_ID)->whereNull('deleted_at')->first();
if (!$organizer || $organizer->name !== 'Konka Wawa') {
    throw new RuntimeException('Organisateur Konka Wawa introuvable : rien n\'est modifié');
}
foreach ($dates as $d) {
    if (!is_file(AFFICHES.'/'.$d['affiche'])) {
        throw new RuntimeException('Affiche manquante : '.$d['affiche'].' : rien n\'est modifié');
    }
    if ($d['id'] !== null) {
        $event = DB::table('events')->where('id', $d['id'])->where('organizer_id', ORGANIZER_ID)->whereNull('deleted_at')->first();
        if (!$event || $event->title !== $titre($d)) {
            throw new RuntimeException('Événement '.$d['id'].' inattendu : rien n\'est modifié');
        }
    }
}

auth()->setUser(\HiEvents\Models\User::findOrFail(USER_ID));

$ids = DB::transaction(function () use ($dates, $titre, $description, $adresse) {
    $ids = [];

    foreach ($dates as $d) {
        if ($d['id'] === null) {
            $existing = DB::table('events')->where('organizer_id', ORGANIZER_ID)->where('title', $titre($d))->whereNull('deleted_at')->value('id');
            $d['id'] = $existing ?? app(CreateEventHandler::class)->handle(new CreateEventDTO(
                title: $titre($d),
                organizer_id: ORGANIZER_ID,
                account_id: ACCOUNT_ID,
                user_id: USER_ID,
                start_date: $d['debut'],
                end_date: $d['fin'],
                description: $description($d),
                timezone: 'Europe/Paris',
                currency: 'EUR',
                category: EventCategory::MUSIC,
                location_details: $adresse($d),
                status: EventStatus::LIVE->name,
            ))->getId();
        }

        app(UpdateEventHandler::class)->handle(new UpdateEventDTO(
            title: $titre($d),
            category: EventCategory::MUSIC,
            account_id: ACCOUNT_ID,
            id: $d['id'],
            start_date: $d['debut'],
            end_date: $d['fin'],
            description: $description($d),
            timezone: 'Europe/Paris',
            location_details: $adresse($d),
        ));

        app(PartialUpdateEventSettingsHandler::class)->handle(new PartialUpdateEventSettingsDTO(
            account_id: ACCOUNT_ID,
            event_id: $d['id'],
            settings: ['location_details' => $adresse($d)->toArray(), 'is_online_event' => false],
        ));

        $hasTicket = DB::table('products')->where('event_id', $d['id'])->where('title', 'Entrée')->whereNull('deleted_at')->exists();
        if (!$hasTicket) {
            $categoryId = DB::table('product_categories')->where('event_id', $d['id'])->whereNull('deleted_at')->orderBy('id')->value('id');
            if (!$categoryId) {
                throw new RuntimeException('Pas de catégorie de billets pour l\'événement '.$d['id']);
            }
            app(CreateProductHandler::class)->handle(new UpsertProductDTO(
                account_id: ACCOUNT_ID,
                event_id: $d['id'],
                product_category_id: $categoryId,
                title: 'Entrée',
                type: ProductPriceType::PAID,
                product_type: ProductType::TICKET,
                prices: collect([new ProductPriceDTO(price: 35.00)]),
                price: 35.00,
                initial_quantity_available: null,
                max_per_order: 20,
                min_per_order: 1,
                is_hidden: false,
                is_visible_at_box_office: false,
                is_sales_paused: false,
            ));
        }

        $ids[] = $d + ['event_id' => $d['id']];
    }

    return $ids;
});

foreach ($ids as $d) {
    $path = AFFICHES.'/'.$d['affiche'];
    app(CreateImageHandler::class)->handle(new CreateImageDTO(
        userId: USER_ID,
        accountId: ACCOUNT_ID,
        image: new UploadedFile($path, $d['affiche'], 'image/jpeg', null, true),
        imageType: ImageType::EVENT_COVER,
        entityId: $d['event_id'],
    ));

    $event = DB::table('events')->where('id', $d['event_id'])->first();
    $ticket = DB::table('products')->where('event_id', $d['event_id'])->where('title', 'Entrée')->whereNull('deleted_at')->first();
    echo $d['event_id'].' | '.$event->title.' | '.$event->status.' | '.$d['debut'].' (Paris) | '.$d['salle'].', '.$d['commune']
        .' | billet '.($ticket ? $ticket->id : 'ABSENT').' | affiche '.$d['affiche']."\n";
}
