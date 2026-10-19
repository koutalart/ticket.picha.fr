<?php
// Innocent Event (compte 3, organisateur 4) : pilote automatique de la page Halloween (événement 7).
// PILOTE_MODE=verifier : affiche la clé attendue « <jour>-<prix> » (ex. J-3-15) ou FIN après la soirée.
// PILOTE_MODE=appliquer + PILOTE_CLE : met en ligne la vidéo /tmp/innocent-pilote/halloween-<clé>.mp4,
// la première phrase du jour (description + message avant paiement) et, à 20 €, rouvre l'entrée simple.

use Carbon\Carbon;
use HiEvents\Services\Application\Handlers\Event\CoverVideo\DTO\UploadEventCoverVideoDTO;
use HiEvents\Services\Application\Handlers\Event\CoverVideo\UploadEventCoverVideoHandler;
use HiEvents\Services\Application\Handlers\EventSettings\DTO\PartialUpdateEventSettingsDTO;
use HiEvents\Services\Application\Handlers\EventSettings\PartialUpdateEventSettingsHandler;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

const EVENT_ID = 7;
const ACCOUNT_ID = 3;
const USER_ID = 6;
const FLASH = 20;
const ENTREE = 10;
const DOSSIER = '/tmp/innocent-pilote';

function pilote(): void
{
    $event = DB::table('events')->where('id', EVENT_ID)->where('organizer_id', 4)->where('account_id', ACCOUNT_ID)->whereNull('deleted_at')->first();
    if (!$event || $event->title !== 'Halloween') {
        throw new RuntimeException('Événement inattendu : rien n\'est modifié');
    }

    $maintenant = Carbon::now('Indian/Mayotte');
    if ($maintenant->gte(Carbon::parse('2026-11-01 06:00', 'Indian/Mayotte'))) {
        echo "CLE=FIN\n";
        return;
    }
    $jours = ['2026-10-26' => 'J-5', '2026-10-27' => 'J-4', '2026-10-28' => 'J-3', '2026-10-29' => 'J-2', '2026-10-30' => 'DEMAIN', '2026-10-31' => 'CE-SOIR'];
    $jour = $jours[$maintenant->toDateString()] ?? 'base';

    $flash = DB::table('product_prices')->where('product_id', FLASH)->first();
    $epuise = $flash && $flash->initial_quantity_available !== null && $flash->quantity_sold >= $flash->initial_quantity_available;
    $prix = $epuise ? '20' : '15';
    $cle = $jour.'-'.$prix;

    if (getenv('PILOTE_MODE') !== 'appliquer') {
        echo 'CLE='.$cle.' | flash vendus '.($flash->quantity_sold ?? '?').'/'.($flash->initial_quantity_available ?? '?')."\n";
        return;
    }
    if (getenv('PILOTE_CLE') !== $cle) {
        throw new RuntimeException('Clé changée entre la vérification et l\'application ('.getenv('PILOTE_CLE').' / '.$cle.') : rien n\'est modifié');
    }
    $video = DOSSIER.'/halloween-'.$cle.'.mp4';
    if (!is_readable($video)) {
        throw new RuntimeException('Vidéo manquante : '.$video.' : rien n\'est modifié');
    }

    $phrases = [
        'base' => 'Halloween au 5/5 avec Innocent Event 🎃',
        'J-5' => 'Plus que 5 jours avant Halloween au 5/5 🎃',
        'J-4' => 'J-4 : Le D et Jah D One débarquent au 5/5 🔥',
        'J-3' => 'J-3 : ton costume est prêt ? 👻',
        'J-2' => 'J-2 : réunis ta team de monstres 🩸',
        'DEMAIN' => 'C\'est demain soir ! Dernière ligne droite pour réserver ta place 🎃',
        'CE-SOIR' => 'C\'est ce soir ! Rendez-vous dès 20:00 au 5/5 🔥',
    ];
    $p = '<p style="margin:0.5em 0px;">';
    $offre = $epuise
        ? 'La vente flash est épuisée : il reste les entrées à 20 € en ligne (25 € sur place). Prends ta place maintenant !'
        : 'Vente flash à 15 € avant le passage au tarif normal à 20 €. Prends ta place maintenant !';
    $description = $p.'<strong>'.$phrases[$jour].'</strong><br />'
        .'Le samedi 31 octobre, on sort les déguisements pour une nuit terrifiante : <strong>LE D SHOWCASE x JAH D ONE</strong>, avec DJ SWEETY, ASSKABOY et DJ BVNG\'Z aux platines 🔥</p>'
        .$p.'Afrobeats, amapiano, afro-caribéen, hip-hop, R&amp;B et kompa toute la nuit.</p>'
        .$p.'Vampires, zombies, sorcières, démons : viens déguisé(e), ramène ta team et sors ton costume le plus terrifiant 👻</p>'
        .$p.'🎬 Le D : <a href="https://www.youtube.com/watch?v=cEMFbokybco">LE D - 2K225 feat. BOLEMVN</a><br />'
        .'🎬 Jah D One : <a href="https://www.youtube.com/watch?v=vaxYr-eZmkw">Jah D One - wyfl riddim</a></p>'
        .$p.'<strong>'.$offre.'</strong></p>';
    $avant = $p.'<strong>'.($jour === 'base' ? 'Tu es à deux clics de vivre Halloween au 5/5 !' : $phrases[$jour]).'</strong><br />Choisis ton billet, valide ta commande et prépare ton déguisement pour <strong>LE D SHOWCASE x JAH D ONE</strong> 🎃🔥</p>'
        .$p.'Les billets sont limités ⏳</p>';

    auth()->setUser(\HiEvents\Models\User::findOrFail(USER_ID));

    DB::transaction(function () use ($description, $avant, $epuise) {
        DB::table('events')->where('id', EVENT_ID)->update(['description' => $description, 'updated_at' => now()]);
        app(PartialUpdateEventSettingsHandler::class)->handle(new PartialUpdateEventSettingsDTO(
            account_id: ACCOUNT_ID,
            event_id: EVENT_ID,
            settings: ['pre_checkout_message' => $avant],
        ));
        if ($epuise) {
            DB::table('products')->where('id', ENTREE)->where('event_id', EVENT_ID)->update(['is_hidden' => false, 'is_visible_at_box_office' => true, 'updated_at' => now()]);
        }
    });

    app(UploadEventCoverVideoHandler::class)->handle(new UploadEventCoverVideoDTO(
        eventId: EVENT_ID,
        accountId: ACCOUNT_ID,
        video: new UploadedFile($video, basename($video), 'video/mp4', null, true),
    ));

    $entree = DB::table('products')->where('id', ENTREE)->first();
    echo 'APPLIQUE='.$cle.' | entrée simple '.($entree->is_hidden ? 'masquée' : 'en vente').' | vidéos actives '
        .DB::table('images')->where('entity_id', EVENT_ID)->where('type', 'EVENT_COVER_VIDEO')->whereNull('deleted_at')->count()."\n";
}

pilote();
