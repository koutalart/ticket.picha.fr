<?php
// Innocent Event (compte 3, organisateur 4) : Halloween (événement 7).
// Vidéo de couverture, affiche (flyer) et textes hérités de Triangle des bermudes remplacés.
// Même traitement que les écrans de l'événement. Relançable : mêmes fichiers, mêmes textes.

use HiEvents\DomainObjects\Enums\ImageType;
use HiEvents\Services\Application\Handlers\Event\CoverVideo\DTO\UploadEventCoverVideoDTO;
use HiEvents\Services\Application\Handlers\Event\CoverVideo\UploadEventCoverVideoHandler;
use HiEvents\Services\Application\Handlers\EventSettings\DTO\PartialUpdateEventSettingsDTO;
use HiEvents\Services\Application\Handlers\EventSettings\PartialUpdateEventSettingsHandler;
use HiEvents\Services\Application\Handlers\Images\CreateImageHandler;
use HiEvents\Services\Application\Handlers\Images\DTO\CreateImageDTO;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

const EVENT_ID = 7;
const ORGANIZER_ID = 4;
const ACCOUNT_ID = 3;
const USER_ID = 6;
const DOSSIER = '/tmp/innocent-halloween';

$p = '<p style="margin:0.5em 0px;">';
$textes = [
    'pre_checkout_message' => $p.'<strong>Tu es à deux clics de vivre Halloween au 5/5 !</strong><br />Choisis ton billet, valide ta commande et prépare ton déguisement pour <strong>LE D SHOWCASE x JAH D ONE</strong> 🎃🔥</p>'
        .$p.'Les billets sont limités ⏳</p>',
    'post_checkout_message' => $p.'🎃 <strong>C’est validé ! Bienvenue à la soirée Halloween d’Innocent Event.</strong></p>'
        .$p.'Ton billet est confirmé. Garde bien ton billet/QR Code : il te sera demandé par Brayan à l’entrée.</p>'
        .$p.'Maintenant, plus qu’une chose à faire : <strong>prépare ton plus beau déguisement ! 👻</strong></p>'
        .$p.'À très vite au 5/5, le samedi 31 octobre 🩸</p>',
    'email_footer_message' => $p.'<strong>Halloween au 5/5</strong><br />Une question concernant ton billet ou l’événement ? Réponds directement à cet e-mail.</p>'
        .$p.'🎟️ Billetterie officielle - Réserve ton billet en ligne et retrouve toutes les informations concernant la soirée.</p>'
        .$p.'© 2026 Innocent Event - Mayotte</p>',
    'seo_title' => 'Halloween au 5/5 à Mayotte, Le D x Jah D One | Billets',
    'seo_description' => 'Soirée Halloween au 5/5 à Mamoudzou le samedi 31 octobre avec Le D Showcase x Jah D One, DJ Sweety, Asskaboy et DJ Bvng\'z. Vente flash à 15 €, réserve ton billet en ligne.',
    'seo_keywords' => 'Halloween Mayotte, soirée Halloween Mamoudzou, Le D, Jah D One, Le D Showcase, 5/5 Mamoudzou, Innocent Event, soirée Mayotte, afrobeats Mayotte, amapiano Mayotte, kompa Mayotte, billet soirée Mayotte',
];

$event = DB::table('events')->where('id', EVENT_ID)->where('organizer_id', ORGANIZER_ID)->where('account_id', ACCOUNT_ID)->whereNull('deleted_at')->first();
if (!$event || $event->title !== 'Halloween') {
    throw new RuntimeException('Événement inattendu : rien n\'est modifié');
}
foreach (['halloween-couverture.mp4', 'flyer-halloween.jpg'] as $fichier) {
    if (!is_readable(DOSSIER.'/'.$fichier)) {
        throw new RuntimeException('Fichier manquant : '.$fichier.' : rien n\'est modifié');
    }
}

auth()->setUser(\HiEvents\Models\User::findOrFail(USER_ID));

app(PartialUpdateEventSettingsHandler::class)->handle(new PartialUpdateEventSettingsDTO(
    account_id: ACCOUNT_ID,
    event_id: EVENT_ID,
    settings: $textes,
));

app(UploadEventCoverVideoHandler::class)->handle(new UploadEventCoverVideoDTO(
    eventId: EVENT_ID,
    accountId: ACCOUNT_ID,
    video: new UploadedFile(DOSSIER.'/halloween-couverture.mp4', 'halloween-couverture.mp4', 'video/mp4', null, true),
));

app(CreateImageHandler::class)->handle(new CreateImageDTO(
    userId: USER_ID,
    accountId: ACCOUNT_ID,
    image: new UploadedFile(DOSSIER.'/flyer-halloween.jpg', 'flyer-halloween.jpg', 'image/jpeg', null, true),
    imageType: ImageType::EVENT_FLYER,
    entityId: EVENT_ID,
));

$s = DB::table('event_settings')->where('event_id', EVENT_ID)->first();
$restes = collect(['pre_checkout_message', 'post_checkout_message', 'email_footer_message', 'seo_title', 'seo_description', 'seo_keywords'])
    ->filter(fn ($c) => stripos((string) $s->$c, 'triangle') !== false || stripos((string) $s->$c, 'bermude') !== false);
echo 'Textes encore sur Triangle : '.($restes->isEmpty() ? 'aucun' : $restes->implode(', '))."\n";
echo 'Images : '.DB::table('images')->where('entity_id', EVENT_ID)->where('entity_type', 'like', '%EventDomainObject')
    ->whereIn('type', ['EVENT_COVER', 'EVENT_SHARE_IMAGE', 'EVENT_COVER_VIDEO', 'EVENT_FLYER'])->whereNull('deleted_at')->pluck('type')->sort()->implode(', ')."\n";
echo 'Frais payés par l\'acheteur : '.($s->pass_platform_fee_to_buyer ? 'oui' : 'NON')."\n";
