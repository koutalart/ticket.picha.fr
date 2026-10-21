<?php
// Concert des Générations (compte 5, événement 14) : page d'inscription façon Les Flammes.
// Thème sombre et accent jaune de la chaîne (événement 14 et page organisateur 8), description en blocs courts.
// La vidéo de couverture est envoyée à part. Relançable : mêmes valeurs.

use HiEvents\DataTransferObjects\AddressDTO;
use HiEvents\DomainObjects\Enums\EventCategory;
use HiEvents\Services\Application\Handlers\Event\DTO\UpdateEventDTO;
use HiEvents\Services\Application\Handlers\Event\UpdateEventHandler;
use HiEvents\Services\Application\Handlers\EventSettings\DTO\PartialUpdateEventSettingsDTO;
use HiEvents\Services\Application\Handlers\EventSettings\PartialUpdateEventSettingsHandler;
use HiEvents\Services\Application\Handlers\Organizer\DTO\PartialUpdateOrganizerSettingsDTO;
use HiEvents\Services\Application\Handlers\Organizer\Settings\PartialUpdateOrganizerSettingsHandler;
use Illuminate\Support\Facades\DB;

const ACCOUNT_ID = 5;
const EVENT_ID = 14;
const USER_ID = 8;

$description = '<p><strong>Pourquoi le Concert des Générations ?</strong></p>'
    .'<p>Comme un pont entre les générations : les artistes d\'aujourd\'hui reprennent les chansons qui ont fait chanter Mayotte.</p>'
    .'<p>Comme un hommage à celles et ceux qui ont accompagné quarante ans d\'images, de fêtes et de souvenirs partagés à l\'antenne.</p>'
    .'<p>Comme une grande soirée de famille, pour fêter ensemble les 40 ans de la télévision à Mayotte.</p>'
    .'<p><strong>Sur scène</strong><br />Méli, RH, Chaldi, Jah D One, Kemaal &amp; O.B., Annice, Maliza, et un dernier artiste bientôt annoncé.<br />Accompagnés en live par un orchestre de musiciens professionnels de Mayotte.</p>'
    .'<p><strong>La soirée</strong><br />Samedi 14 novembre 2026, dans les locaux de Mayotte La 1ère.<br />18h30 : accueil, photocall et cocktail<br />20h15 : le concert<br />22h00 : photo officielle</p>'
    .'<p>La soirée est précédée, à 17h, d\'une cérémonie de remise de trophées à une quarantaine de Chama de Debaa et de Chigoma, sur invitation.</p>'
    .'<p><strong>Votre place</strong><br />Entrée gratuite, sur inscription, dans la limite des places disponibles.<br />Jusqu\'à 2 places par inscription, chaque personne étant nommée.<br />Une pièce d\'identité au nom de l\'inscription sera demandée à l\'entrée.<br />Inscriptions du lundi 26 octobre au vendredi 13 novembre à midi.</p>'
    .'<p><strong>Rendez-vous le 21 décembre</strong><br />Le concert sera diffusé à la télévision et sur les réseaux sociaux de Mayotte La 1ère, le jour anniversaire des 40 ans.</p>'
    .'<p>#40ansTVMayotte #ConcertDesGénérations</p>';

$event = DB::table('events')->where('id', EVENT_ID)->where('account_id', ACCOUNT_ID)->where('organizer_id', 8)->whereNull('deleted_at')->first();
if (!$event || $event->title !== 'Le Concert des Générations') {
    throw new RuntimeException('Événement inattendu : rien n\'est modifié');
}
$lieu = json_decode(DB::table('event_settings')->where('event_id', EVENT_ID)->value('location_details') ?? '{}', true) ?: [];

auth()->setUser(\HiEvents\Models\User::findOrFail(USER_ID));

DB::transaction(function () use ($event, $description, $lieu) {
    app(UpdateEventHandler::class)->handle(new UpdateEventDTO(
        title: $event->title,
        category: EventCategory::MUSIC,
        account_id: ACCOUNT_ID,
        id: EVENT_ID,
        start_date: '2026-11-14 18:30:00',
        end_date: '2026-11-14 22:30:00',
        description: $description,
        timezone: 'Indian/Mayotte',
        location_details: AddressDTO::from($lieu),
    ));

    app(PartialUpdateEventSettingsHandler::class)->handle(new PartialUpdateEventSettingsDTO(
        account_id: ACCOUNT_ID,
        event_id: EVENT_ID,
        settings: ['homepage_theme_settings' => [
            'mode' => 'dark',
            'accent' => '#ffba00',
            'background' => '#0a0a0a',
            'background_type' => 'COLOR',
            'font_family' => 'Outfit',
        ]],
    ));
});

app(PartialUpdateOrganizerSettingsHandler::class)->handle(PartialUpdateOrganizerSettingsDTO::from([
    'organizer_id' => 8,
    'account_id' => (string) ACCOUNT_ID,
    'homepage_theme_settings' => [
        'mode' => 'dark',
        'accent' => '#ffba00',
        'background' => '#0a0a0a',
        'background_type' => 'COLOR',
        'font_family' => 'Outfit',
    ],
]));

$e = DB::table('events')->where('id', EVENT_ID)->first(['start_date', 'end_date']);
echo 'Événement 14 | '.$e->start_date.' → '.$e->end_date.' UTC | thème '.DB::table('event_settings')->where('event_id', EVENT_ID)->value('homepage_theme_settings')
    ."\nOrganisateur 8 | thème ".DB::table('organizer_settings')->where('organizer_id', 8)->value('homepage_theme_settings')."\n";
