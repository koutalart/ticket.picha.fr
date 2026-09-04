// DIGIT — Etape 3 : association du bracelet officiel de validation terrain
// Bracelet : BRJVTWX76TRA96MKKF (Jour 3, jamais utilise, statut GENERATED confirme)
// Seule ecriture autorisee : BraceletAssociationService::associate() sur CE bracelet.
// Un nouvel attendee dedie est cree (A-41YB6YM a deja un bracelet actif - BRFUEZZU5GMZNEMFKA -
// impossible de reutiliser sans y toucher, ce qui est explicitement interdit ici).

$eventId = 4;
$targetCode = 'BRJVTWX76TRA96MKKF';

echo "=== 0. Confirmation prealable : le bracelet cible est bien GENERATED, non associe ===\n";
$before = \Digit\Bracelets\Domain\Models\DigitBracelet::query()->where('code', $targetCode)->first();
if ($before === null) {
    echo "ERREUR: bracelet introuvable, arret.\n";
    exit(1);
}
echo "code={$before->code} status={$before->status->value} attendee_id=" . ($before->attendee_id ?? 'NULL') . "\n";
if ($before->status->value !== 'GENERATED' || $before->attendee_id !== null) {
    echo "ERREUR: le bracelet n'est pas dans l'etat attendu, arret sans modification.\n";
    exit(1);
}

echo "\n=== 1. Snapshot global du lot AVANT association (pour verification finale) ===\n";
$beforeCounts = \Illuminate\Support\Facades\DB::table('digit_bracelets')
    ->where('event_id', $eventId)
    ->select('status', \Illuminate\Support\Facades\DB::raw('count(*) as c'))
    ->groupBy('status')->get();
foreach ($beforeCounts as $s) { echo "{$s->status}: {$s->c}\n"; }

echo "\n=== 2. Localiser le produit Grand Public de la check-in list Jeudi 06 (id=3, deja utilisee pour le device 225) ===\n";
$checkInListId = 3;
$productIds = \Illuminate\Support\Facades\DB::table('product_check_in_lists')
    ->where('check_in_list_id', $checkInListId)
    ->pluck('product_id');
$products = \Illuminate\Support\Facades\DB::table('products')->whereIn('id', $productIds)->get(['id', 'title']);
foreach ($products as $p) { echo "product_id={$p->id} title={$p->title}\n"; }
$grandPublicProduct = $products->first(fn($p) => str_contains(strtolower($p->title), 'grand public'));
$productPriceId = \Illuminate\Support\Facades\DB::table('product_prices')->where('product_id', $grandPublicProduct->id)->value('id');
echo "-> retenu: product_id={$grandPublicProduct->id} product_price_id={$productPriceId}\n";

echo "\n=== 3. Creer UN attendee dedie a ce test terrain (native CreateAttendeeHandler) ===\n";
$createAttendeeHandler = app(\HiEvents\Services\Application\Handlers\Attendee\CreateAttendeeHandler::class);
$attendee = $createAttendeeHandler->handle(\HiEvents\Services\Application\Handlers\Attendee\DTO\CreateAttendeeDTO::fromArray([
    'first_name' => 'Terrain',
    'last_name' => 'Validation Somaroho',
    'email' => 'terrain-validation-somaroho@ticket.picha.fr',
    'product_id' => $grandPublicProduct->id,
    'product_price_id' => $productPriceId,
    'event_id' => $eventId,
    'send_confirmation_email' => false,
    'amount_paid' => 20000,
    'locale' => 'fr',
]));
echo "-> attendee cree: id={$attendee->getId()} public_id={$attendee->getPublicId()}\n";

echo "\n=== 4. Association (BraceletAssociationService::associate() - seule ecriture sur digit_bracelets) ===\n";
$associationService = app(\Digit\Bracelets\Domain\Services\BraceletAssociationService::class);
$result = $associationService->associate(new \Digit\Bracelets\Domain\DTO\AssociateBraceletDTO(
    code: $targetCode,
    attendeeId: $attendee->getId(),
    eventId: $eventId,
));
echo "-> resultat: code={$targetCode} status={$result->status->value} attendee_id={$result->attendeeId} assigned_at={$result->assignedAt}\n";

echo "\n=== 5. Snapshot global du lot APRES association (doit etre: GENERATED -1, ASSIGNED +1, REVOKED inchange) ===\n";
$afterCounts = \Illuminate\Support\Facades\DB::table('digit_bracelets')
    ->where('event_id', $eventId)
    ->select('status', \Illuminate\Support\Facades\DB::raw('count(*) as c'))
    ->groupBy('status')->get();
foreach ($afterCounts as $s) { echo "{$s->status}: {$s->c}\n"; }

echo "\n=== 6. Verification stricte : SEUL ce bracelet a change (comparaison ligne a ligne des IDs assignes) ===\n";
$allAssigned = \Illuminate\Support\Facades\DB::table('digit_bracelets')
    ->where('event_id', $eventId)
    ->where('status', 'ASSIGNED')
    ->pluck('code');
echo "Tous les bracelets ASSIGNED apres cette operation: " . $allAssigned->implode(', ') . "\n";
echo "(Attendu : exactement BRFUEZZU5GMZNEMFKA [test E2E precedent] + BRJVTWX76TRA96MKKF [ce test] - rien d'autre)\n";

$checkInListRow = \Illuminate\Support\Facades\DB::table('check_in_lists')->where('id', $checkInListId)->first(['name', 'short_id']);
echo "\nCheck-in list associee au produit de cet attendee: {$checkInListRow->name} ({$checkInListRow->short_id})\n";

echo "\nDONE\n";
