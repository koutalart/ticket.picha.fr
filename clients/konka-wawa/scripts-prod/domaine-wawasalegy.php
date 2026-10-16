<?php
// Konka Wawa (organisateur 9, compte 6) : domaine personnalisé wawasalegy.com.
// Passe par le même traitement que l'écran d'administration (contrôles d'unicité et vidage du cache CORS).

use HiEvents\Services\Application\Handlers\Admin\DTO\UpdateOrganizerCustomDomainDTO;
use HiEvents\Services\Application\Handlers\Admin\UpdateOrganizerCustomDomainHandler;
use Illuminate\Support\Facades\DB;

$organizer = DB::table('organizers')->where('id', 9)->where('account_id', 6)->whereNull('deleted_at')->first();
if (!$organizer || $organizer->name !== 'Konka Wawa') {
    throw new RuntimeException('Organisateur Konka Wawa introuvable : rien n\'est modifié');
}
if ($organizer->custom_domain !== null) {
    throw new RuntimeException('Un domaine est déjà enregistré ('.$organizer->custom_domain.') : rien n\'est modifié');
}

$updated = app(UpdateOrganizerCustomDomainHandler::class)->handle(new UpdateOrganizerCustomDomainDTO(
    accountId: 6,
    organizerId: 9,
    customDomain: 'wawasalegy.com',
));

echo $updated->getId().' | '.$updated->getName().' | domaine : '.$updated->getCustomDomain()."\n";
