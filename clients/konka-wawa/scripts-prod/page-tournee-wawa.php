<?php
// Konka Wawa (organisateur 9, compte 6) : page organisateur « Tournée » (maquette validée le 17/10).
// 1. ville affichée dans les dates (attribut public « ville ») sur les 5 événements
// 2. couleur générale jaune #ffd23f (accent du thème de l'organisateur, le reste inchangé)
// 3. contenu de la page (site_content, champs existants conservés)
// 4. vidéo de couverture de la tournée
// 5. modèle de page TOUR
// Relançable : chaque étape remet la même valeur.

use HiEvents\DomainObjects\Enums\OrganizerHomepageTemplate;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Services\Application\Handlers\Admin\DTO\UpdateOrganizerHomepageTemplateDTO;
use HiEvents\Services\Application\Handlers\Admin\DTO\UpdateOrganizerSiteContentDTO;
use HiEvents\Services\Application\Handlers\Admin\UpdateOrganizerHomepageTemplateHandler;
use HiEvents\Services\Application\Handlers\Admin\UpdateOrganizerSiteContentHandler;
use HiEvents\Services\Application\Handlers\Organizer\CoverVideo\DTO\UploadOrganizerCoverVideoDTO;
use HiEvents\Services\Application\Handlers\Organizer\CoverVideo\UploadOrganizerCoverVideoHandler;
use HiEvents\Services\Application\Handlers\Organizer\DTO\PartialUpdateOrganizerSettingsDTO;
use HiEvents\Services\Application\Handlers\Organizer\Settings\PartialUpdateOrganizerSettingsHandler;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

const ORGANIZER_ID = 9;
const ACCOUNT_ID = 6;
const USER_ID = 10;
const VIDEO = '/tmp/wawa-tournee/wawa-tournee-couverture.mp4';
const ACCENT = '#ffd23f';

$villes = [8 => 'Marseille', 9 => 'Colmar', 10 => 'Toulouse', 12 => 'Lyon', 11 => 'Limoges'];

$contenu = [
    'tagline' => 'Tournée 2026, 25 ans de scène',
    'intro_title' => 'Wawa en tournée',
    'intro_text' => 'Le roi du salegy fête 25 ans de scène. La tournée démarre en France en novembre, et de nouvelles villes s\'ajoutent au fil des annonces.',
    'upcoming_note' => 'Et bientôt d\'autres villes',
    'notify_url' => 'https://www.instagram.com/wawasalegyfusion',
    'about_headline' => '25 ans de salegy, sur scène',
    'story' => '<p>Depuis Madagascar, Wawa fait danser toutes les générations. Pour ses 25 ans de scène, il part en tournée avec un show « grand complet » et tous les tubes repris en chœur, de « Vaha la chaîne » à « Sabina ».</p>',
    'stats' => [
        ['value' => '25', 'label' => 'ans de scène'],
        ['value' => 'Nov.', 'label' => 'premières dates en France'],
        ['value' => '21 h', 'label' => 'jusqu\'au bout de la nuit'],
    ],
    'videos' => [
        ['youtube_id' => 'AUouq1alg10', 'title' => '400 Volt', 'subtitle' => 'Marseille'],
        ['youtube_id' => 'FDemamDMeQs', 'title' => 'Maresaka', 'subtitle' => 'Colmar'],
        ['youtube_id' => '87YqPAzwmJU', 'title' => 'Chocolat', 'subtitle' => 'Toulouse'],
        ['youtube_id' => '-bQn2lp518w', 'title' => 'Ao zay', 'subtitle' => 'Lyon'],
        ['youtube_id' => 'XOUcfYV4JdQ', 'title' => 'Sabina', 'subtitle' => 'Limoges'],
    ],
    'practical' => [
        ['title' => 'Billet électronique', 'text' => 'Reçu par e-mail juste après l\'achat, à présenter sur téléphone ou imprimé à l\'entrée.'],
        ['title' => 'Paiement sécurisé', 'text' => 'Par carte bancaire. Le prix final est affiché avant le paiement.'],
        ['title' => 'Une question ?', 'text' => 'Infoline 07 59 33 09 01, ou le bouton Contact de la page.'],
    ],
    'partners' => [
        ['name' => 'Yas'], ['name' => 'JAC by GM'], ['name' => 'RN Prod'],
        ['name' => 'SW Prod'], ['name' => 'Jobilahy Production'], ['name' => 'Nightconcept Gasy'],
    ],
];

$interdits = ['·', '•', '—', '–'];
array_walk_recursive($contenu, function ($valeur) use ($interdits) {
    foreach ($interdits as $c) {
        if (is_string($valeur) && str_contains($valeur, $c)) {
            throw new RuntimeException('Caractère interdit « '.$c.' » dans : '.$valeur);
        }
    }
});

$organizer = DB::table('organizers')->where('id', ORGANIZER_ID)->where('account_id', ACCOUNT_ID)->whereNull('deleted_at')->first();
if (!$organizer || $organizer->name !== 'Konka Wawa') {
    throw new RuntimeException('Organisateur inattendu : rien n\'est modifié');
}
foreach ($villes as $eventId => $ville) {
    $event = DB::table('events')->where('id', $eventId)->where('organizer_id', ORGANIZER_ID)->where('account_id', ACCOUNT_ID)->whereNull('deleted_at')->first();
    if (!$event || $event->title !== 'Wawa, 25 ans de scène à '.$ville) {
        throw new RuntimeException('Événement '.$eventId.' inattendu : rien n\'est modifié');
    }
}
if (!is_readable(VIDEO) || filesize(VIDEO) > 15 * 1024 * 1024) {
    throw new RuntimeException('Vidéo manquante ou trop lourde : rien n\'est modifié');
}

auth()->setUser(\HiEvents\Models\User::findOrFail(USER_ID));

DB::transaction(function () use ($villes, $contenu, $organizer) {
    foreach ($villes as $eventId => $ville) {
        $attributs = collect(json_decode((string) DB::table('events')->where('id', $eventId)->value('attributes'), true) ?: [])
            ->reject(fn ($a) => in_array(strtolower(trim($a['name'] ?? '')), ['ville', 'city', 'tour_city'], true))
            ->values()->all();
        $attributs[] = ['name' => 'ville', 'value' => $ville, 'is_public' => true];
        app(EventRepositoryInterface::class)->updateByIdWhere(
            id: $eventId,
            attributes: ['attributes' => $attributs],
            where: ['account_id' => ACCOUNT_ID],
        );
    }

    $theme = json_decode((string) DB::table('organizer_settings')->where('organizer_id', ORGANIZER_ID)->value('homepage_theme_settings'), true) ?: [];
    $theme['accent'] = ACCENT;
    app(PartialUpdateOrganizerSettingsHandler::class)->handle(PartialUpdateOrganizerSettingsDTO::from([
        'organizer_id' => ORGANIZER_ID,
        'account_id' => (string) ACCOUNT_ID,
        'homepage_theme_settings' => $theme,
    ]));

    $existant = is_string($organizer->site_content) ? (json_decode($organizer->site_content, true) ?: []) : [];
    app(UpdateOrganizerSiteContentHandler::class)->handle(new UpdateOrganizerSiteContentDTO(
        accountId: ACCOUNT_ID,
        organizerId: ORGANIZER_ID,
        siteContent: array_merge($existant, $contenu),
    ));
});

app(UploadOrganizerCoverVideoHandler::class)->handle(new UploadOrganizerCoverVideoDTO(
    organizerId: ORGANIZER_ID,
    accountId: ACCOUNT_ID,
    video: new UploadedFile(VIDEO, basename(VIDEO), 'video/mp4', null, true),
));

app(UpdateOrganizerHomepageTemplateHandler::class)->handle(new UpdateOrganizerHomepageTemplateDTO(
    accountId: ACCOUNT_ID,
    organizerId: ORGANIZER_ID,
    homepageTemplate: OrganizerHomepageTemplate::TOUR,
));

$o = DB::table('organizers')->where('id', ORGANIZER_ID)->first();
$sc = json_decode((string) $o->site_content, true) ?: [];
$accent = json_decode((string) DB::table('organizer_settings')->where('organizer_id', ORGANIZER_ID)->value('homepage_theme_settings'), true)['accent'] ?? '?';
$video = DB::table('images')->where('entity_id', ORGANIZER_ID)->where('type', 'ORGANIZER_COVER_VIDEO')->whereNull('deleted_at')->count();
echo 'Modèle : '.$o->homepage_template.' | accent : '.$accent.' | vidéo de couverture : '.$video.' | clips : '.count($sc['videos'] ?? []).' | partenaires : '.count($sc['partners'] ?? [])."\n";
foreach ($villes as $eventId => $ville) {
    $attr = collect(json_decode((string) DB::table('events')->where('id', $eventId)->value('attributes'), true) ?: [])->firstWhere('name', 'ville');
    echo $eventId.' | ville affichée : '.($attr['value'] ?? '?').' | publique : '.(!empty($attr['is_public']) ? 'oui' : 'NON')."\n";
}
