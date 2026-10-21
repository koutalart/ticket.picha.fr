<?php
// Concert des Générations (compte 5, événement 14), validé par Jo le 22/10 :
// vidéo d'en-tête N&B, image de couverture fixe sans texte (l'image de partage ne change pas),
// jauge à 315 places, e-mail de rappel programmé le jeudi 12/11 à 10h (heure de Mayotte).
// Les réglages d'inscription sont dans inscriptions-concert.php.

use HiEvents\DomainObjects\Enums\ImageType;
use HiEvents\DomainObjects\Enums\MessageTypeEnum;
use HiEvents\Repository\Interfaces\ProductPriceRepositoryInterface;
use HiEvents\Services\Application\Handlers\Event\CoverVideo\DTO\UploadEventCoverVideoDTO;
use HiEvents\Services\Application\Handlers\Event\CoverVideo\UploadEventCoverVideoHandler;
use HiEvents\Services\Application\Handlers\Images\CreateImageHandler;
use HiEvents\Services\Application\Handlers\Images\DTO\CreateImageDTO;
use HiEvents\Services\Application\Handlers\Message\DTO\SendMessageDTO;
use HiEvents\Services\Application\Handlers\Message\SendMessageHandler;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

const ACCOUNT_ID = 5;
const EVENT_ID = 14;
const BILLET_ID = 27;
const USER_ID = 8;
const DOSSIER = '/tmp/concert';
const OBJET = 'Le Concert des Générations, ce samedi 14 novembre';

$event = DB::table('events')->where('id', EVENT_ID)->where('account_id', ACCOUNT_ID)->where('organizer_id', 8)->whereNull('deleted_at')->first();
$prix = DB::table('product_prices')->where('product_id', BILLET_ID)->whereNull('deleted_at')->get(['id']);
if (!$event || $event->title !== 'Le Concert des Générations' || $prix->count() !== 1) {
    throw new RuntimeException('Événement ou billet inattendu : rien n\'est modifié');
}
foreach (['concert-entete-hd-90-nb.mp4', 'concert-couverture-fixe.jpg'] as $f) {
    if (!is_readable(DOSSIER.'/'.$f)) {
        throw new RuntimeException('Fichier manquant : '.$f.' : rien n\'est modifié');
    }
}

auth()->setUser(\HiEvents\Models\User::findOrFail(USER_ID));

// jauge 315 places (sur-réservation de 5 %)
app(ProductPriceRepositoryInterface::class)->updateWhere(['initial_quantity_available' => 315], ['id' => $prix->first()->id]);
cache()->forget('event.'.EVENT_ID.'.available_product_quantities');

// image de couverture fixe sans texte
app(CreateImageHandler::class)->handle(new CreateImageDTO(
    userId: USER_ID,
    accountId: ACCOUNT_ID,
    image: new UploadedFile(DOSSIER.'/concert-couverture-fixe.jpg', 'concert-des-generations.jpg', 'image/jpeg', null, true),
    imageType: ImageType::EVENT_COVER,
    entityId: EVENT_ID,
));

// vidéo d'en-tête
app(UploadEventCoverVideoHandler::class)->handle(new UploadEventCoverVideoDTO(
    eventId: EVENT_ID,
    accountId: ACCOUNT_ID,
    video: new UploadedFile(DOSSIER.'/concert-entete-hd-90-nb.mp4', 'concert-des-generations.mp4', 'video/mp4', null, true),
));

// e-mail de rappel à J-2, programmé une seule fois
if (!DB::table('messages')->where('event_id', EVENT_ID)->where('subject', OBJET)->whereNull('deleted_at')->exists()) {
    app(SendMessageHandler::class)->handle(new SendMessageDTO(
        account_id: ACCOUNT_ID,
        event_id: EVENT_ID,
        subject: OBJET,
        message: '<p>Madame, Monsieur,</p>'
            .'<p>Nous aurons le plaisir de vous accueillir ce samedi 14 novembre, dans les locaux de Mayotte La 1ère à Mamoudzou, pour le Concert des Générations.</p>'
            .'<p>L\'accueil débutera à 18h30, et le concert à 20h15. Une pièce d\'identité au nom inscrit sur votre billet vous sera demandée à l\'entrée.</p>'
            .'<p>Si vous ne pouvez plus être des nôtres, nous vous remercions de nous en informer, afin que votre place puisse bénéficier à une autre personne.</p>'
            .'<p>Au plaisir de vous retrouver,</p>'
            .'<p>L\'équipe de Mayotte La 1ère et KULTUR\'M</p>',
        type: MessageTypeEnum::ALL_ATTENDEES,
        is_test: false,
        send_copy_to_current_user: false,
        sent_by_user_id: USER_ID,
        scheduled_at: '2026-11-12 10:00:00',
    ));
}

echo 'Jauge | '.DB::table('product_prices')->where('id', $prix->first()->id)->value('initial_quantity_available')." places\n";
echo 'Images | '.DB::table('images')->where('entity_id', EVENT_ID)->where('entity_type', 'like', '%EventDomainObject')->whereNull('deleted_at')->pluck('type')->sort()->implode(', ')."\n";
$m = DB::table('messages')->where('event_id', EVENT_ID)->where('subject', OBJET)->first(['status', 'scheduled_at', 'type']);
echo 'Rappel | '.($m ? $m->type.' '.$m->status.' le '.$m->scheduled_at.' UTC' : 'ABSENT')."\n";
