<?php
// Konka Wawa (organisateur 9, compte 6) : descriptions « récit de tournée », un clip officiel par étape.
// Sans effet de bord si relancé : mêmes textes.

use HiEvents\DataTransferObjects\AddressDTO;
use HiEvents\DomainObjects\Enums\EventCategory;
use HiEvents\Services\Application\Handlers\Event\DTO\UpdateEventDTO;
use HiEvents\Services\Application\Handlers\Event\UpdateEventHandler;
use Illuminate\Support\Facades\DB;

const ORGANIZER_ID = 9;
const ACCOUNT_ID = 6;
const USER_ID = 10;

$clip = fn (string $id, string $titre) => '<p>🎬 Le clip : <a href="https://www.youtube.com/watch?v='.$id.'">'.$titre.'</a></p>';

$dates = [
    ['id' => 8, 'ville' => 'Marseille', 'debut' => '2026-11-07 21:00:00', 'fin' => '2026-11-08 05:00:00',
        'salle' => 'Florida Palace', 'adresse' => '162-164 boulevard Mireille Lauze', 'cp' => '13010', 'commune' => 'Marseille',
        'texte' => '<p><strong>C\'est à Marseille que tout commence.</strong><br />Pour ses 25 ans de scène, Wawa prend la route et donne le coup d\'envoi de sa tournée française au Florida Palace. Pour lancer l\'histoire, il fallait un titre qui envoie : « 400 Volt », et toute la salle sous tension dès la première date. 🔥</p>'
            .$clip('AUouq1alg10', 'Wawa Salegy – 400 Volt')
            .'<p>Samedi 7 novembre dès 21 h · 162-164 boulevard Mireille Lauze, 13010 Marseille.</p>'
            .'<p><strong>Entrée 35 € : réserve ta place pour le grand départ.</strong></p>'],
    ['id' => 9, 'ville' => 'Colmar', 'debut' => '2026-11-14 21:00:00', 'fin' => '2026-11-15 05:00:00',
        'salle' => 'Salle polyvalente', 'adresse' => '1A rue du Vignoble', 'cp' => '67210', 'commune' => 'Goxwiller',
        'texte' => '<p><strong>Une semaine après Marseille, cap sur l\'Alsace.</strong><br />La tournée remonte vers l\'est et la famille gasy d\'Alsace l\'attend de pied ferme. À la Salle polyvalente de Goxwiller, c\'est « Maresaka » qui sera à l\'honneur : un de ces titres que tout le monde reprend en chœur.</p>'
            .$clip('FDemamDMeQs', 'Wawa Salegy – Maresaka')
            .'<p>Samedi 14 novembre dès 21 h · 1A rue du Vignoble, 67210 Goxwiller.</p>'
            .'<p><strong>Entrée 35 € : l\'Alsace a son rendez-vous, prends ta place.</strong></p>'],
    ['id' => 10, 'ville' => 'Toulouse', 'debut' => '2026-11-21 21:00:00', 'fin' => '2026-11-22 05:00:00',
        'salle' => 'Salle Amira', 'adresse' => '9 rue Jean Moulin', 'cp' => '31830', 'commune' => 'Plaisance-du-Touch',
        'texte' => '<p><strong>Mi-parcours : la tournée descend dans le Sud-Ouest.</strong><br />Deux villes déjà conquises, et Wawa arrive à Toulouse avec une énergie intacte. Troisième étape à la Salle Amira, avec « Chocolat » au programme d\'une nuit salegy comme le Sud-Ouest les aime.</p>'
            .$clip('87YqPAzwmJU', 'Wawa Salegy – Chocolat')
            .'<p>Samedi 21 novembre dès 21 h · 9 rue Jean Moulin, 31830 Plaisance-du-Touch.</p>'
            .'<p><strong>Entrée 35 € : réserve avant que la salle ne se remplisse.</strong></p>'],
    ['id' => 12, 'ville' => 'Lyon', 'debut' => '2026-11-27 21:00:00', 'fin' => '2026-11-28 05:00:00',
        'salle' => 'Le Gold Réception', 'adresse' => '7 route des Troques', 'cp' => '69630', 'commune' => 'Chaponost',
        'texte' => '<p><strong>Avant-dernière étape : Lyon ouvre le week-end final.</strong><br />Quatre semaines sur la route, et la tournée arrive au Gold Réception un vendredi soir. « Ao zay » pour chauffer la salle : c\'est l\'avant-dernière occasion de vivre les 25 ans de scène de Wawa en live.</p>'
            .$clip('-bQn2lp518w', 'Wawa Salegy – Ao zay')
            .'<p>Vendredi 27 novembre dès 21 h · 7 route des Troques, 69630 Chaponost.</p>'
            .'<p><strong>Entrée 35 € : ne rate pas l\'avant-dernière date.</strong></p>'],
    ['id' => 11, 'ville' => 'Limoges', 'debut' => '2026-11-28 21:00:00', 'fin' => '2026-11-29 05:00:00',
        'salle' => 'Pavillon de Buxerolles', 'adresse' => '20 rue Arthur Groussier', 'cp' => '87100', 'commune' => 'Limoges',
        'texte' => '<p><strong>Limoges, la grande finale.</strong><br />De Marseille à Lyon, la tournée a fait danser toute la France. Elle se termine au Pavillon de Buxerolles, et pour la dernière nuit il fallait le plus grand tube : « Sabina », que tout le monde connaît par cœur. On finit en beauté, tous ensemble. 🎉</p>'
            .$clip('XOUcfYV4JdQ', 'Wawa Salegy ft. Bacoili – Sabina')
            .'<p>Samedi 28 novembre dès 21 h · 20 rue Arthur Groussier, 87100 Limoges.</p>'
            .'<p><strong>Entrée 35 € : dernière chance, prends ta place pour la finale.</strong></p>'],
];

$titre = fn (array $d) => 'Wawa – 25 ans de scène · '.$d['ville'];

foreach ($dates as $d) {
    $event = DB::table('events')->where('id', $d['id'])->where('organizer_id', ORGANIZER_ID)->where('account_id', ACCOUNT_ID)->whereNull('deleted_at')->first();
    if (!$event || $event->title !== $titre($d)) {
        throw new RuntimeException('Événement '.$d['id'].' inattendu : rien n\'est modifié');
    }
}

auth()->setUser(\HiEvents\Models\User::findOrFail(USER_ID));

DB::transaction(function () use ($dates, $titre) {
    foreach ($dates as $d) {
        app(UpdateEventHandler::class)->handle(new UpdateEventDTO(
            title: $titre($d),
            category: EventCategory::MUSIC,
            account_id: ACCOUNT_ID,
            id: $d['id'],
            start_date: $d['debut'],
            end_date: $d['fin'],
            description: $d['texte'],
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
    $description = DB::table('events')->where('id', $d['id'])->value('description');
    preg_match('/<strong>(.*?)<\/strong>/u', $description, $premiere);
    echo $d['id'].' | '.$d['ville'].' | '.($premiere[1] ?? '?').' | lien clip : '.(str_contains($description, 'youtube.com/watch') ? 'oui' : 'NON')."\n";
}
