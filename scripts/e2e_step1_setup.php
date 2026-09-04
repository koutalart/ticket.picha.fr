// DIGIT — E2E validation Somaroho — Step 1: setup (create attendee, associate ONE bracelet)
// Read + one-time writes ONLY to: attendees/orders (native, via native Handler) and
// exactly TWO rows of digit_bracelets (one -> ASSIGNED via BraceletAssociationService,
// one -> REVOKED via the same service) - the same production code path the real
// system will use. Does NOT touch the other 93 018 bracelets, no migration, no
// schema change, no native Hi.Events file touched.

echo "=== A. Locate Jour 1 (5 aout 2026) check-in list for event_id=4 ===\n";
$eventId = 4;
$accountId = \Illuminate\Support\Facades\DB::table('events')->where('id', $eventId)->value('account_id');
echo "account_id (Somaroho) = {$accountId}\n";

$lists = \Illuminate\Support\Facades\DB::table('check_in_lists')
    ->where('event_id', $eventId)
    ->orderBy('id')
    ->get(['id', 'name', 'short_id', 'activates_at', 'expires_at']);

foreach ($lists as $l) {
    echo sprintf("id=%d name=%s short_id=%s activates_at=%s expires_at=%s\n", $l->id, $l->name, $l->short_id, $l->activates_at, $l->expires_at);
}

$jour1List = $lists->first(function ($l) {
    return $l->activates_at !== null && str_starts_with((string) $l->activates_at, '2026-08-05');
});

if ($jour1List === null) {
    echo "!! Aucune check-in list trouvee avec activates_at commencant par 2026-08-05. Verifier manuellement la liste ci-dessus et adapter le script.\n";
    exit(1);
}

echo "\n-> Jour 1 check-in list retenue: id={$jour1List->id} name={$jour1List->name} short_id={$jour1List->short_id}\n";

echo "\n=== B. Locate the 'Grand Public' product for that check-in list ===\n";
$productIds = \Illuminate\Support\Facades\DB::table('product_check_in_lists')
    ->where('check_in_list_id', $jour1List->id)
    ->pluck('product_id');

$products = \Illuminate\Support\Facades\DB::table('products')
    ->whereIn('id', $productIds)
    ->get(['id', 'title', 'product_category_id']);

foreach ($products as $p) {
    $cat = \Illuminate\Support\Facades\DB::table('product_categories')->where('id', $p->product_category_id)->value('name');
    echo "product_id={$p->id} title={$p->title} category={$cat}\n";
}

$grandPublicProduct = $products->first(fn ($p) => str_contains(strtolower($p->title), 'grand public'));

if ($grandPublicProduct === null) {
    echo "!! Aucun produit 'Grand Public' trouve parmi ceux ci-dessus. Adapter manuellement le product_id retenu ci-dessous.\n";
    exit(1);
}

$productPriceId = \Illuminate\Support\Facades\DB::table('product_prices')
    ->where('product_id', $grandPublicProduct->id)
    ->value('id');

echo "\n-> Produit retenu: id={$grandPublicProduct->id} title={$grandPublicProduct->title} product_price_id={$productPriceId}\n";

echo "\n=== C. Create ONE real attendee (native CreateAttendeeHandler, amount_paid=50000 MGA) ===\n";
$createAttendeeHandler = app(\HiEvents\Services\Application\Handlers\Attendee\CreateAttendeeHandler::class);

$attendee = $createAttendeeHandler->handle(\HiEvents\Services\Application\Handlers\Attendee\DTO\CreateAttendeeDTO::fromArray([
    'first_name' => 'E2E',
    'last_name' => 'Test Somaroho',
    'email' => 'e2e-test-somaroho@ticket.picha.fr',
    'product_id' => $grandPublicProduct->id,
    'product_price_id' => $productPriceId,
    'event_id' => $eventId,
    'send_confirmation_email' => false,
    'amount_paid' => 50000,
    'locale' => 'fr',
]));

echo "-> attendee created: id={$attendee->getId()} public_id={$attendee->getPublicId()}\n";

echo "\n=== D. Pick ONE unassociated bracelet from the END of the master lot (id DESC) - avoids any overlap with a future Jour1 print-batch export taken from the front ===\n";
$testBracelet = \Digit\Bracelets\Domain\Models\DigitBracelet::query()
    ->where('event_id', $eventId)
    ->where('status', 'GENERATED')
    ->whereNull('attendee_id')
    ->orderByDesc('id')
    ->first();

echo "-> candidate bracelet: id={$testBracelet->id} code={$testBracelet->code}\n";

echo "\n=== E. Associate this ONE bracelet to the attendee (BraceletAssociationService - production code path) ===\n";
$associationService = app(\Digit\Bracelets\Domain\Services\BraceletAssociationService::class);

$result = $associationService->associate(new \Digit\Bracelets\Domain\DTO\AssociateBraceletDTO(
    code: $testBracelet->code,
    attendeeId: $attendee->getId(),
    eventId: $eventId,
));

echo "-> associated: status={$result->status->value} attendee_id={$result->attendeeId} assigned_at={$result->assignedAt}\n";

echo "\n=== F. Prepare a SECOND bracelet, revoke it directly (for the 'revoked bracelet' rejection test) ===\n";
$revokedTestBracelet = \Digit\Bracelets\Domain\Models\DigitBracelet::query()
    ->where('event_id', $eventId)
    ->where('status', 'GENERATED')
    ->whereNull('attendee_id')
    ->orderByDesc('id')
    ->skip(1)
    ->first();

echo "-> candidate bracelet for revoke-test: id={$revokedTestBracelet->id} code={$revokedTestBracelet->code}\n";

// revoke() only requires a non-terminal status (see BraceletStatus::isTerminal()) -
// no need to associate it to any attendee first, a GENERATED bracelet can be
// revoked directly, exactly like a real "mark this wristband as lost/void
// before it was ever handed out" operation.
$revokeResult = $associationService->revoke($revokedTestBracelet->code, $eventId, 'E2E test - revoked bracelet rejection scenario');
echo "-> revoked: status={$revokeResult->status->value} revoked_at={$revokeResult->revokedAt}\n";

echo "\n=== G. Compute the exact QR payloads to use for testing ===\n";
$keyService = app(\Digit\Bracelets\Security\EventSecurityKeyService::class);
$signatureService = app(\Digit\Bracelets\Domain\Services\BraceletSignatureService::class);
$secret = $keyService->findSecret($eventId);

$validSignature = $signatureService->sign($testBracelet->code, $secret);
$validPayload = "DGT1.{$testBracelet->code}.{$validSignature}";

$revokedSignature = $signatureService->sign($revokedTestBracelet->code, $secret);
$revokedPayload = "DGT1.{$revokedTestBracelet->code}.{$revokedSignature}";

// Tampered: flip the last character of a valid signature - must fail HMAC check.
$tamperedSignature = substr($validSignature, 0, -1) . ($validSignature[-1] === 'A' ? 'B' : 'A');
$tamperedPayload = "DGT1.{$testBracelet->code}.{$tamperedSignature}";

echo "\n----------------------------------------------------------------\n";
echo "account_id = {$accountId}\n";
echo "checkInListShortId (Jour1) = {$jour1List->short_id}\n";
echo "checkInList id (Jour1, pour --check-in-list-id) = {$jour1List->id}\n";
echo "VALID payload (happy path + duplicate test) = {$validPayload}\n";
echo "TAMPERED payload (signature rejection test)  = {$tamperedPayload}\n";
echo "REVOKED payload (revoked rejection test)     = {$revokedPayload}\n";
echo "attendee public_id = {$attendee->getPublicId()}\n";
echo "----------------------------------------------------------------\n";
echo "\nDONE — copiez les 4 lignes ci-dessus, elles servent pour l'etape 2 (tests curl).\n";
