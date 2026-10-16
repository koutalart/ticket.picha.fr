<?php
// Konka Wawa (organisateur 9, compte 6) : 4 dates de la tournée « 25 ans de scène », publiées, sans billets.
// Rien n'est créé si un contrôle échoue ou si un événement du même titre existe déjà.

use HiEvents\DataTransferObjects\AddressDTO;
use HiEvents\DomainObjects\Enums\EventCategory;
use HiEvents\DomainObjects\Status\EventStatus;
use HiEvents\Services\Application\Handlers\Event\CreateEventHandler;
use HiEvents\Services\Application\Handlers\Event\DTO\CreateEventDTO;
use Illuminate\Support\Facades\DB;

const ORGANIZER_ID = 9;
const ACCOUNT_ID = 6;
const USER_ID = 10;

$dates = [
    ['ville' => 'Marseille', 'debut' => '2026-11-07 20:00:00', 'fin' => '2026-11-08 05:00:00'],
    ['ville' => 'Colmar', 'debut' => '2026-11-14 20:00:00', 'fin' => '2026-11-15 05:00:00'],
    ['ville' => 'Toulouse', 'debut' => '2026-11-21 20:00:00', 'fin' => '2026-11-22 05:00:00'],
    ['ville' => 'Limoges', 'debut' => '2026-11-28 20:00:00', 'fin' => '2026-11-29 05:00:00'],
];

$description = fn (string $ville) => '<p><strong>Wawa fête ses 25 ans de scène et passe par '.$ville.' !</strong><br />'
    .'25 ans de tubes, de danses et de souvenirs : Wawa célèbre son anniversaire de scène avec une grande tournée en France, '
    .'et il s\'arrête chez toi pour une soirée qui va compter. 🔥</p>'
    .'<p>Viens chanter, danser et revivre tous les titres qui ont marqué ces 25 ans, en live et avec toute la famille comorienne et mahoraise de '.$ville.'.</p>'
    .'<p><strong>Les places partent vite : réserve la tienne dès maintenant !</strong></p>';

$organizer = DB::table('organizers')->where('id', ORGANIZER_ID)->where('account_id', ACCOUNT_ID)->whereNull('deleted_at')->first();
if (!$organizer || $organizer->name !== 'Konka Wawa') {
    throw new RuntimeException('Organisateur Konka Wawa introuvable : rien n\'est créé');
}
if (!DB::table('account_users')->where('account_id', ACCOUNT_ID)->where('user_id', USER_ID)->whereNull('deleted_at')->exists()) {
    throw new RuntimeException('Utilisateur 10 absent du compte 6 : rien n\'est créé');
}

$titre = fn (string $ville) => 'Wawa – 25 ans de scène · '.$ville;
foreach ($dates as $date) {
    if (DB::table('events')->where('organizer_id', ORGANIZER_ID)->where('title', $titre($date['ville']))->whereNull('deleted_at')->exists()) {
        throw new RuntimeException('« '.$titre($date['ville']).' » existe déjà : rien n\'est créé');
    }
}

auth()->setUser(\HiEvents\Models\User::findOrFail(USER_ID));

$handler = app(CreateEventHandler::class);

DB::transaction(function () use ($dates, $handler, $titre, $description) {
    foreach ($dates as $date) {
        $event = $handler->handle(new CreateEventDTO(
            title: $titre($date['ville']),
            organizer_id: ORGANIZER_ID,
            account_id: ACCOUNT_ID,
            user_id: USER_ID,
            start_date: $date['debut'],
            end_date: $date['fin'],
            description: $description($date['ville']),
            timezone: 'Europe/Paris',
            currency: 'EUR',
            category: EventCategory::MUSIC,
            location_details: new AddressDTO(city: $date['ville'], country: 'FR'),
            status: EventStatus::LIVE->name,
        ));

        echo $event->getId().' | '.$event->getTitle().' | '.$event->getStatus().' | '.$date['debut'].' → '.$date['fin']." (heure de Paris)\n";
    }
});
