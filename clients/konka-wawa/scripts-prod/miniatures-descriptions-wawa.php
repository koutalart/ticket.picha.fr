<?php
// Konka Wawa (organisateur 9, compte 6) : descriptions harmonisées + miniature par ville
// (image principale et image de partage). Sans effet de bord si relancé : mêmes valeurs, mêmes images.

use HiEvents\DataTransferObjects\AddressDTO;
use HiEvents\DomainObjects\Enums\EventCategory;
use HiEvents\DomainObjects\Enums\ImageType;
use HiEvents\Services\Application\Handlers\Event\DTO\UpdateEventDTO;
use HiEvents\Services\Application\Handlers\Event\UpdateEventHandler;
use HiEvents\Services\Application\Handlers\Images\CreateImageHandler;
use HiEvents\Services\Application\Handlers\Images\DTO\CreateImageDTO;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

const ORGANIZER_ID = 9;
const ACCOUNT_ID = 6;
const USER_ID = 10;
const MINIATURES = '/tmp/wawa-miniatures';

$dates = [
    ['id' => 8, 'ville' => 'Marseille', 'jour' => 'samedi 7 novembre', 'debut' => '2026-11-07 21:00:00', 'fin' => '2026-11-08 05:00:00',
        'lieu' => 'au Florida Palace', 'salle' => 'Florida Palace', 'adresse' => '162-164 boulevard Mireille Lauze', 'cp' => '13010', 'commune' => 'Marseille'],
    ['id' => 9, 'ville' => 'Colmar', 'jour' => 'samedi 14 novembre', 'debut' => '2026-11-14 21:00:00', 'fin' => '2026-11-15 05:00:00',
        'lieu' => 'à la Salle polyvalente de Goxwiller', 'salle' => 'Salle polyvalente', 'adresse' => '1A rue du Vignoble', 'cp' => '67210', 'commune' => 'Goxwiller'],
    ['id' => 10, 'ville' => 'Toulouse', 'jour' => 'samedi 21 novembre', 'debut' => '2026-11-21 21:00:00', 'fin' => '2026-11-22 05:00:00',
        'lieu' => 'à la Salle Amira', 'salle' => 'Salle Amira', 'adresse' => '9 rue Jean Moulin', 'cp' => '31830', 'commune' => 'Plaisance-du-Touch'],
    ['id' => 12, 'ville' => 'Lyon', 'jour' => 'vendredi 27 novembre', 'debut' => '2026-11-27 21:00:00', 'fin' => '2026-11-28 05:00:00',
        'lieu' => 'au Gold Réception', 'salle' => 'Le Gold Réception', 'adresse' => '7 route des Troques', 'cp' => '69630', 'commune' => 'Chaponost'],
    ['id' => 11, 'ville' => 'Limoges', 'jour' => 'samedi 28 novembre', 'debut' => '2026-11-28 21:00:00', 'fin' => '2026-11-29 05:00:00',
        'lieu' => 'au Pavillon de Buxerolles', 'salle' => 'Pavillon de Buxerolles', 'adresse' => '20 rue Arthur Groussier', 'cp' => '87100', 'commune' => 'Limoges'],
];

$titre = fn (array $d) => 'Wawa – 25 ans de scène · '.$d['ville'];

$description = fn (array $d) => '<p><strong>Wawa fête ses 25 ans de scène à '.e($d['ville']).' !</strong><br />'
    .'« Vaha la chaîne », « Fondy eh! » et tous les tubes qui font danser Madagascar depuis 25 ans : '
    .'Wawa pose ses valises '.e($d['lieu']).' pour une grande soirée salegy. 🔥</p>'
    .'<p>Viens chanter, danser et célébrer avec toute la communauté malgache. Rendez-vous le '.e($d['jour']).' dès 21 h, '
    .e($d['adresse']).', '.e($d['cp']).' '.e($d['commune']).'.</p>'
    .'<p><strong>Entrée 35 € : les places partent vite, réserve la tienne dès maintenant !</strong></p>';

$fichier = fn (array $d) => MINIATURES.'/wawa-'.strtolower($d['ville']).'-miniature.jpg';

foreach ($dates as $d) {
    $event = DB::table('events')->where('id', $d['id'])->where('organizer_id', ORGANIZER_ID)->where('account_id', ACCOUNT_ID)->whereNull('deleted_at')->first();
    if (!$event || $event->title !== $titre($d)) {
        throw new RuntimeException('Événement '.$d['id'].' inattendu : rien n\'est modifié');
    }
    if (!is_readable($fichier($d))) {
        throw new RuntimeException('Miniature manquante : '.$fichier($d).' : rien n\'est modifié');
    }
}

auth()->setUser(\HiEvents\Models\User::findOrFail(USER_ID));

DB::transaction(function () use ($dates, $titre, $description) {
    foreach ($dates as $d) {
        app(UpdateEventHandler::class)->handle(new UpdateEventDTO(
            title: $titre($d),
            category: EventCategory::MUSIC,
            account_id: ACCOUNT_ID,
            id: $d['id'],
            start_date: $d['debut'],
            end_date: $d['fin'],
            description: $description($d),
            timezone: 'Europe/Paris',
            location_details: new AddressDTO(
                venue_name: $d['salle'],
                address_line_1: $d['adresse'],
                city: $d['commune'],
                zip_or_postal_code: $d['cp'],
                country: 'FR',
            ),
        ));
    }
});

foreach ($dates as $d) {
    foreach ([ImageType::EVENT_COVER, ImageType::EVENT_SHARE_IMAGE] as $type) {
        app(CreateImageHandler::class)->handle(new CreateImageDTO(
            userId: USER_ID,
            accountId: ACCOUNT_ID,
            image: new UploadedFile($fichier($d), basename($fichier($d)), 'image/jpeg', null, true),
            imageType: $type,
            entityId: $d['id'],
        ));
    }

    $images = DB::table('images')->where('entity_id', $d['id'])->where('entity_type', 'like', '%EventDomainObject')
        ->whereIn('type', ['EVENT_COVER', 'EVENT_SHARE_IMAGE'])->whereNull('deleted_at')->count();
    echo $d['id'].' | '.$titre($d).' | description à jour | images principale + partage : '.$images."\n";
}
