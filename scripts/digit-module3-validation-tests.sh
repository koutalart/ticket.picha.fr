#!/bin/bash
#
# DIGIT Ticket - Module 3 hardening validation suite
#
# Runs the 4 scenarios required before Module 3 (QR scan system) can be
# considered production-grade:
#   1. Simultaneous double-scan (race condition) on the same attendee
#   2. Redis-down failover (scan must still work, DB is the source of truth)
#   3. Load test with 200 concurrent scanners - each scanner is its OWN
#      device token, since the per-device rate limit (FailOpenThrottle,
#      120 req/min) is intentionally an anti-abuse measure per physical
#      scanner, not a ceiling on how many scanners an event can run at
#      once. Reusing one token for all 200 requests would trip that limit
#      and produce 429s that look like failures but aren't.
#   4. Latency injection on Redis (fail-open must not hang the request)
#
# This script provisions its OWN throwaway device token(s) automatically
# (no manual copy-paste of a token required) and always picks attendees
# that have NEVER been checked in yet (rather than a fixed position), so
# it is safe to re-run repeatedly without false negatives from attendee
# reuse. Device tokens never touch the terminal output and are revoked
# automatically at the end of the run.
#
# Do not Ctrl+C mid-run: test 3 seeds several hundred attendees one at a
# time via Hi.Events' own Handlers (not raw inserts), which can take a
# minute or two - this is expected, not a hang.

set -uo pipefail

BASE_URL="https://api-staging.ticket.picha.fr"
CHECKIN_LIST="cil_ux1akoh2bQbSt"
CHECKIN_LIST_ID=1
COMPOSE="docker compose -f /opt/digit-ticket/docker-compose.staging.yml"

if ! command -v bc >/dev/null 2>&1; then
  echo "Installation de bc (nécessaire pour les calculs de latence)..."
  sudo apt-get install -y bc >/dev/null 2>&1
fi

tinker_value() {
  $COMPOSE exec -T backend php artisan tinker --execute="$1" 2>/dev/null | tr -d '\r\n'
}

fresh_attendee() {
  tinker_value "echo DB::table('attendees')->whereNotIn('id', function(\$q) { \$q->select('attendee_id')->from('attendee_check_ins')->where('check_in_list_id', $CHECKIN_LIST_ID)->whereNull('deleted_at'); })->orderBy('id')->value('public_id');"
}

echo "Provisioning d'un device de test temporaire (auto, aucune saisie manuelle)..."
RAW=$(tinker_value "
\$t = \Illuminate\Support\Str::random(48);
\$id = DB::table('digit_scan_devices')->insertGetId([
    'name' => 'Module3 Validation Suite (auto)',
    'account_id' => 4,
    'event_id' => 3,
    'check_in_list_id' => $CHECKIN_LIST_ID,
    'token_hash' => hash('sha256', \$t),
    'created_at' => now(),
    'updated_at' => now(),
]);
echo \$id . '|' . \$t;
")
DEVICE_ID="${RAW%%|*}"
TOKEN="${RAW##*|}"
echo "Device de test cree (ID: $DEVICE_ID, jamais affiche en clair, sera revoque en fin de script)."

TEST3_DEVICE_IDS=""

cleanup() {
  echo ""
  echo "Nettoyage: revocation du device de test temporaire (ID $DEVICE_ID)..."
  $COMPOSE exec -T backend php artisan digit:scan:device:revoke "$DEVICE_ID" > /dev/null 2>&1
  if [ -n "$TEST3_DEVICE_IDS" ]; then
    echo "Nettoyage: revocation des devices de test 3 (200 scanners simules)..."
    $COMPOSE exec -T backend php artisan tinker --execute="DB::table('digit_scan_devices')->whereIn('id', [${TEST3_DEVICE_IDS#,}])->update(['revoked_at' => now()]);" > /dev/null 2>&1
  fi
}
trap cleanup EXIT

echo "======================================================"
echo "TEST 1 - Double-scan simultane (race condition)"
echo "======================================================"
ATTENDEE_1=$(fresh_attendee)
echo "Attendee de test (jamais check-in avant ce run): $ATTENDEE_1"

N=25
RESULTS_DIR=$(mktemp -d)
for i in $(seq 1 "$N"); do
  (curl -s -o "$RESULTS_DIR/r_$i.json" \
    -X POST "$BASE_URL/digit/scan/check-in-lists/$CHECKIN_LIST/check-ins" \
    -H "Content-Type: application/json" \
    -H "Authorization: Bearer $TOKEN" \
    -d "{\"public_id\": \"$ATTENDEE_1\", \"action\": \"check-in\"}") &
done
wait

RECORDED=$(grep -l '"result":"recorded"' "$RESULTS_DIR"/r_*.json 2>/dev/null | wc -l)
DUPLICATE=$(grep -l '"result":"duplicate"' "$RESULTS_DIR"/r_*.json 2>/dev/null | wc -l)
OTHER=$((N - RECORDED - DUPLICATE))

echo "Sur $N requetes simultanees sur le meme attendee (jamais scanne avant):"
echo "  - recorded (attendu: 1): $RECORDED"
echo "  - duplicate (attendu: $((N - 1))): $DUPLICATE"
echo "  - autre/erreur (attendu: 0): $OTHER"
if [ "$RECORDED" -eq 1 ] && [ "$OTHER" -eq 0 ]; then
  echo "  ==> PASS: un seul scan enregistre, la contrainte DB tient sous concurrence reelle."
else
  echo "  ==> FAIL: $RECORDED scans enregistres (attendu 1) - probleme de race condition."
fi
rm -rf "$RESULTS_DIR"

echo ""
echo "======================================================"
echo "TEST 2 - Panne Redis (fail-open)"
echo "======================================================"
ATTENDEE_2=$(fresh_attendee)
echo "Attendee de test: $ATTENDEE_2"

$COMPOSE stop redis > /dev/null 2>&1
sleep 2

echo "-- Scan #1 (frais) avec Redis eteint --"
RESP1=$(curl -s -w "\nHTTP_CODE:%{http_code}" -X POST "$BASE_URL/digit/scan/check-in-lists/$CHECKIN_LIST/check-ins" \
  -H "Content-Type: application/json" -H "Authorization: Bearer $TOKEN" \
  -d "{\"public_id\": \"$ATTENDEE_2\", \"action\": \"check-in\"}")
echo "$RESP1"

echo "-- Scan #2 (meme attendee, doit etre duplicate) avec Redis toujours eteint --"
RESP2=$(curl -s -w "\nHTTP_CODE:%{http_code}" -X POST "$BASE_URL/digit/scan/check-in-lists/$CHECKIN_LIST/check-ins" \
  -H "Content-Type: application/json" -H "Authorization: Bearer $TOKEN" \
  -d "{\"public_id\": \"$ATTENDEE_2\", \"action\": \"check-in\"}")
echo "$RESP2"

$COMPOSE start redis > /dev/null 2>&1
echo "Redis redemarre."

if echo "$RESP1" | grep -q "HTTP_CODE:200" && echo "$RESP2" | grep -q "HTTP_CODE:409"; then
  echo "  ==> PASS: le scan fonctionne toujours correctement (200 puis 409) sans Redis."
else
  echo "  ==> FAIL: comportement inattendu sans Redis, voir les reponses ci-dessus."
fi

echo ""
echo "======================================================"
echo "TEST 3 - Charge (200 scanners distincts, 1 scan chacun)"
echo "======================================================"
echo "Extension du pool d'attendees si besoin (peut prendre 1-2 minutes, ne pas interrompre)..."
$COMPOSE exec -T backend php artisan digit:demo:seed --attendees=400 --checked-in=0 > /dev/null 2>&1 || true

echo "Provisioning de 200 devices de test temporaires (un par scanner simule)..."
PAIRS=$(tinker_value "
\$attendees = DB::table('attendees')->whereNotIn('id', function(\$q) { \$q->select('attendee_id')->from('attendee_check_ins')->where('check_in_list_id', $CHECKIN_LIST_ID)->whereNull('deleted_at'); })->orderBy('id')->limit(200)->pluck('public_id');
\$lines = [];
foreach (\$attendees as \$publicId) {
    \$t = \Illuminate\Support\Str::random(48);
    \$id = DB::table('digit_scan_devices')->insertGetId([
        'name' => 'Module3 Test3 Scanner (auto)',
        'account_id' => 4,
        'event_id' => 3,
        'check_in_list_id' => $CHECKIN_LIST_ID,
        'token_hash' => hash('sha256', \$t),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    \$lines[] = \$id . ':' . \$publicId . ':' . \$t;
}
echo implode('|', \$lines);
")

IFS='|' read -ra SCANNERS <<< "$PAIRS"
echo "Nombre de scanners/attendees pour le test de charge: ${#SCANNERS[@]}"

RESULTS_DIR=$(mktemp -d)
START=$(date +%s.%N)
for entry in "${SCANNERS[@]}"; do
  IFS=':' read -r dev_id pid tok <<< "$entry"
  TEST3_DEVICE_IDS="$TEST3_DEVICE_IDS,$dev_id"
  (curl -s -o /dev/null -w "%{http_code} %{time_total}\n" \
    -X POST "$BASE_URL/digit/scan/check-in-lists/$CHECKIN_LIST/check-ins" \
    -H "Content-Type: application/json" -H "Authorization: Bearer $tok" \
    -d "{\"public_id\": \"$pid\", \"action\": \"check-in\"}" >> "$RESULTS_DIR/load.log") &
done
wait
END=$(date +%s.%N)

TOTAL=$(wc -l < "$RESULTS_DIR/load.log")
OK=$(grep -c "^200 " "$RESULTS_DIR/load.log" || true)
DUP=$(grep -c "^409 " "$RESULTS_DIR/load.log" || true)
ERR=$((TOTAL - OK - DUP))
AVG_TIME=$(awk '{sum+=$2; count++} END {if(count>0) print sum/count; else print 0}' "$RESULTS_DIR/load.log")
MAX_TIME=$(awk '{if($2>max) max=$2} END {print max}' "$RESULTS_DIR/load.log")
DURATION=$(echo "$END - $START" | bc)

echo "Resultats sur $TOTAL requetes concurrentes (200 scanners distincts) en ${DURATION}s:"
echo "  - 200 (recorded): $OK"
echo "  - 409 (duplicate): $DUP"
echo "  - autres/erreurs: $ERR"
echo "  - latence moyenne: ${AVG_TIME}s"
echo "  - latence max: ${MAX_TIME}s"
if [ "$ERR" -eq 0 ]; then
  echo "  ==> PASS: aucune erreur sous charge avec 200 scanners distincts."
else
  echo "  ==> ATTENTION: $ERR requetes en erreur - inspecter $RESULTS_DIR/load.log"
fi
rm -rf "$RESULTS_DIR"

echo ""
echo "======================================================"
echo "TEST 4 - Injection de latence Redis"
echo "======================================================"
ATTENDEE_4=$(fresh_attendee)
echo "Attendee de test: $ATTENDEE_4"

echo "Simulation d'un Redis lent (DEBUG SLEEP 8s)..."
$COMPOSE exec -T redis redis-cli DEBUG SLEEP 8 > /dev/null 2>&1 &
SLEEP_PID=$!
sleep 0.5

START=$(date +%s.%N)
RESP4=$(curl -s -w "\nHTTP_CODE:%{http_code}" -X POST "$BASE_URL/digit/scan/check-in-lists/$CHECKIN_LIST/check-ins" \
  -H "Content-Type: application/json" -H "Authorization: Bearer $TOKEN" \
  -d "{\"public_id\": \"$ATTENDEE_4\", \"action\": \"check-in\"}")
END=$(date +%s.%N)
wait "$SLEEP_PID" 2>/dev/null

echo "$RESP4"
DURATION=$(echo "$END - $START" | bc)
echo "Duree de la requete: ${DURATION}s"

if echo "$RESP4" | grep -q "HTTP_CODE:200"; then
  if (( $(echo "$DURATION < 3" | bc -l) )); then
    echo "  ==> PASS: scan reussi en moins de 3s malgre un Redis artificiellement lent (fail-open effectif)."
  else
    echo "  ==> ATTENTION: scan reussi mais a pris ${DURATION}s - le fail-open ne coupe pas assez vite, a investiguer."
  fi
else
  echo "  ==> FAIL: le scan n'a pas reussi pendant l'injection de latence."
fi

echo ""
echo "======================================================"
echo "TESTS TERMINES"
echo "======================================================"
