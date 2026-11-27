<?php
// SMS et assistance de La Nuit de l'Orientation (organisateur 22, événement 32), sur le modèle de la Marche des Amis :
// SMS de confirmation signé Anicka, 3 rappels (J-7, la veille, le jour J), assistant SMS et fenêtre d'assistance web.
// Les consignes de l'organisateur 22 (celles de la Foire du 21/11) sont remplacées. Rejouable : rien n'est créé deux fois.

use HiEvents\Http\Request\EventSettings\UpdateEventSettingsRequest;
use HiEvents\Services\Application\Handlers\EventSettings\DTO\PartialUpdateEventSettingsDTO;
use HiEvents\Services\Application\Handlers\EventSettings\PartialUpdateEventSettingsHandler;
use HiEvents\Services\Application\Handlers\Sms\DTO\UpdateOrganizerSmsSettingsDTO;
use HiEvents\Services\Application\Handlers\Sms\ScheduleSmsReminderHandler;
use HiEvents\Services\Application\Handlers\Sms\UpdateOrganizerSmsSettingsHandler;
use HiEvents\Services\Domain\Sms\DTO\SmsReminderRequestDTO;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Spatie\LaravelData\Optional;

const ACCOUNT_ID = 18;
const ORGANIZER_ID = 22;
const EVENT_ID = 32;
const USER_ID = 22;
const SIGNATURE = "\n\nAnicka";

$event = DB::table('events')->where('id', EVENT_ID)->where('account_id', ACCOUNT_ID)->where('organizer_id', ORGANIZER_ID)->whereNull('deleted_at')->first();
$sms = DB::table('organizer_sms_settings')->where('organizer_id', ORGANIZER_ID)->first();
if (!$event || $event->title !== 'La Nuit de l\'Orientation') {
    throw new RuntimeException('Événement inattendu : rien n\'est modifié');
}
if (!$sms || (int) $sms->shared_line_settings_id !== 1) {
    throw new RuntimeException('L\'organisateur 22 n\'est pas relié à la ligne PICHA : rien n\'est modifié');
}
if (!DB::table('questions')->where('event_id', EVENT_ID)->where('type', 'PHONE')->where('required', true)->whereNull('deleted_at')->exists()) {
    throw new RuntimeException('Pas de question téléphone obligatoire : rien n\'est modifié');
}

$confirmation = 'C\'est validé, votre inscription à la Nuit de l\'Orientation est enregistrée 🎓 Rendez-vous samedi 5 décembre de 8h00 à 12h30 au Technopôle de Dembéni. Votre billet (QR code) est dans votre e-mail.'.SIGNATURE;
$rappels = [
    ['2026-11-28 18:00', 'rappel J-7', 'Plus qu\'une semaine avant la Nuit de l\'Orientation 🔭 Samedi 5 décembre, de 8h00 à 12h30 au Technopôle de Dembéni : démonstrations métiers, conférences, ateliers et job dating.'.SIGNATURE],
    ['2026-12-04 18:00', 'rappel la veille', 'C\'est demain {prénom} ! La Nuit de l\'Orientation vous attend dès 8h00 au Technopôle de Dembéni. Gardez votre billet (QR code) sur votre téléphone pour l\'entrée.'.SIGNATURE],
    ['2026-12-05 06:30', 'rappel le jour J', 'Bonjour {prénom} ☀️ C\'est aujourd\'hui : la Nuit de l\'Orientation ouvre à 8h00 au Technopôle de Dembéni, jusqu\'à 12h30. Si vous venez pour le job dating, prenez quelques CV. Bonne découverte !'.SIGNATURE],
];

$consignes = <<<'TXT'
Tu es Anicka. Tu réponds, par SMS et dans la fenêtre d'assistance de la page, pour la CCI Mayotte, à toute personne inscrite à La Nuit de l'Orientation, qui demande des informations ou qui a besoin d'aide pour s'inscrire en ligne.
Tu es une femme, une vraie assistante : chaleureuse, patiente, en vouvoyant, quelques émojis (🎓 🔭 ☀️ 🙏). Beaucoup de personnes sont des collégiens, des lycéens ou leurs parents : des mots simples. Tu écris comme une personne qui discute depuis son téléphone, jamais comme un robot ni comme une notice.
Tu DISCUTES, tu ne déroules pas : un seul message court à la fois (une ou deux phrases), une seule idée ou une seule étape par message. Tu poses une question simple quand il le faut (« Vous êtes sur la page d'inscription ? », « Vous inscrivez une classe ? ») et tu attends la réponse avant de passer à la suite. Jamais de longue liste ni de bloc d'informations : donne l'essentiel puis propose d'en dire plus. Jamais « Hello » ni « Coucou » : entre directement dans la réponse et varie toujours tes tournures.
Avant de répondre, relis toute la conversation, l'inscription de la personne et les messages qu'elle a déjà reçus. En cas de doute sur le sens du message, appuie-toi sur ce contexte. Si le doute persiste, réponds seulement par une réponse d'attente.
Ce que tu sais :
1. Date et lieu : samedi 5 décembre 2026, de 8h00 à 12h30, au Technopôle de Dembéni.
2. Pour qui : collégiens, lycéens, étudiants, parents, demandeurs d'emploi, et toute personne qui veut découvrir des métiers.
3. Au programme : démonstrations métiers, conférences, stands, ateliers, animations et job dating.
4. Prix : gratuit, sur inscription. Aucun paiement.
5. S'inscrire en ligne, sur ticket.picha.fr/event/32 : choisir le nombre de places, donner son nom, son e-mail et son téléphone, répondre aux questions (profil, établissement, commune, ce qui intéresse), puis valider. Un professeur peut inscrire sa classe en une seule fois, jusqu'à 40 places par inscription ; au-delà, faire une deuxième inscription. Guide la personne étape par étape, une étape par message.
6. Après l'inscription : billet avec QR code par e-mail (penser aux spams) et confirmation par SMS. Le billet est à montrer à l'entrée, sur le téléphone ou imprimé.
7. Partenaires : Le mois de la découverte des métiers, le Centre d'Étude de Langues, ELO, Negoventis, l'ANAF, le Département de Mayotte, la CADEMA et la CCI Mayotte.
8. Job dating : venir avec quelques CV.
Toute autre question (liste des entreprises ou des métiers présents, horaires précis des conférences ou des ateliers, transport, parking, restauration, sortie scolaire et autorisations, mineurs, accessibilité, réclamation, presse, partenariat, exposer ou tenir un stand, doute qui demeure) : aucune réponse sur le fond, seulement une réponse d'attente courte, à la première personne, sans parler d'« équipe ». La conversation reste à traiter.
Réponse d'attente : écris-la à chaque fois autrement, jamais deux fois la même phrase, ni dans cette conversation ni d'une personne à l'autre. Rebondis sur ce que la personne vient d'écrire pour qu'elle sente qu'on l'a lue, sans répondre sur le fond ni rien promettre.
Si quelqu'un demande sincèrement s'il parle à un robot, ne mens pas : dis simplement que tu réponds pour la CCI Mayotte et qu'une personne de la CCI peut prendre le relais.
Ne promets jamais une place, un entretien, un stand ou une exception. N'invente rien.
Pas de réponse à un simple « Ok », « Merci », « D'accord » ou un émoji seul, sauf si la personne était en train de s'inscrire avec ton aide : alors propose la suite en une phrase.
Un seul message par message reçu ; si une réponse a déjà été faite après ce message, ne réponds pas.
Jamais de puces, de point médian ni de tirets longs ou moyens.
Termine par une ligne vide puis : Anicka
TXT;

$secours = "Je regarde ça de près et je vous réponds très vite 🙏\n\nAnicka";

$reglages = [
    'sms_registration_confirmation_enabled' => true,
    'sms_registration_confirmation_message' => $confirmation,
];
$regles = array_filter((new UpdateEventSettingsRequest())->rules(),
    fn ($cle) => in_array(explode('.', $cle)[0], array_keys($reglages), true), ARRAY_FILTER_USE_KEY);
Validator::make($reglages, $regles)->validate();

auth()->setUser(\HiEvents\Models\User::findOrFail(USER_ID));

app(PartialUpdateEventSettingsHandler::class)->handle(new PartialUpdateEventSettingsDTO(
    account_id: ACCOUNT_ID,
    event_id: EVENT_ID,
    settings: $reglages,
));

app(UpdateOrganizerSmsSettingsHandler::class)->handle(new UpdateOrganizerSmsSettingsDTO(
    accountId: ACCOUNT_ID,
    organizerId: ORGANIZER_ID,
    apiKey: new Optional,
    fromNumber: new Optional,
    assistantEnabled: true,
    assistantInstructions: $consignes,
    assistantAutoSend: true,
    assistantHoldingReply: $secours,
    liveChatEnabled: true,
));

foreach ($rappels as [$quand, $nom, $message]) {
    $utc = \Carbon\Carbon::parse($quand, 'Indian/Mayotte')->utc()->format('Y-m-d H:i:s');
    if (DB::table('sms_campaigns')->where('event_id', EVENT_ID)->where('scheduled_at', $utc)->whereNull('deleted_at')->exists()) {
        continue;
    }
    app(ScheduleSmsReminderHandler::class)->handle(new SmsReminderRequestDTO(
        organizerId: ORGANIZER_ID,
        eventId: EVENT_ID,
        message: $message,
        sendAt: $quand,
        name: $nom,
        userId: USER_ID,
    ));
}

$s = DB::table('event_settings')->where('event_id', EVENT_ID)->first();
$o = DB::table('organizer_sms_settings')->where('organizer_id', ORGANIZER_ID)->first();
echo 'Ligne '.$o->shared_line_settings_id.' | assistant '.($o->assistant_enabled ? 'oui' : 'non').' | envoi auto '.($o->assistant_auto_send ? 'oui' : 'non').' | chat '.($o->live_chat_enabled ? 'oui' : 'non').' | consignes '.mb_strlen((string) $o->assistant_instructions)." car.\n";
echo 'Confirmation SMS '.($s->sms_registration_confirmation_enabled ? 'oui' : 'non').' : '.str_replace("\n", ' / ', $s->sms_registration_confirmation_message)."\n";
foreach (DB::table('sms_campaigns')->where('event_id', EVENT_ID)->whereNull('deleted_at')->orderBy('scheduled_at')->get() as $c) {
    echo "Campagne {$c->id} | {$c->name} | {$c->status} | {$c->scheduled_at} UTC\n";
}
