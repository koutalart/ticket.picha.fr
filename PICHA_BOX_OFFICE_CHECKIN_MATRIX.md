# PICHA Box Office — Matrice de décision du check-in natif

Date : 3 septembre 2026 · Source unique : lecture de code. Aucun test exécuté.
Périmètre : **scanner natif Hi.Events** (`POST /public/check-in-lists/{short_id}/check-ins`), conformément à la décision Jo du 3 sept. Le module DIGIT `/digit/scan` (Device Registry, `ScanCoordinatorService`) est **hors périmètre**.

---

## 1. Chaîne d'appel

```
POST /public/check-in-lists/{check_in_list_short_id}/check-ins
  route: routes/api.php:540  (groupe /public, throttle global api = 180/min/IP, PAS d'auth)
  -> CreateAttendeeCheckInPublicAction  (app/Http/Actions/CheckInLists/Public/)
       catch CannotCheckInException -> HTTP 409 (message)
  -> CreateAttendeeCheckInPublicHandler::handle
  -> CreateAttendeeCheckInService::checkInAttendees(shortId, ip, [ {public_id, action} ])
       A. getCheckInList(shortId)                 -> CannotCheckInException si absente
       B. validateCheckInListIsActive             -> CannotCheckInException si expirée / pas encore active
       C. getAttendees([public_id...])            -> CannotCheckInException si un code inconnu (ou collision S4)
       D. fetchEventSettings, fetchExistingCheckIns (lecture groupée)
       E. pour chaque attendee: processIndividualCheckIn
```

`processIndividualCheckIn` (`CreateAttendeeCheckInService.php:168-209`), **ordre exact** :

| Étape | Méthode | Échec = |
|---|---|---|
| 1 | `verifyAttendeeBelongsToCheckInList` | `throw CannotCheckInException` → **409, tout le lot avorté** |
| 2 | `getExistingCheckIn` (en mémoire, lu à l'étape D) | renvoie `{checkIn: <existant>, error: "already checked in"}` — **pas d'insert**, HTTP 200 avec `errors[]` |
| 3 | `validateAttendeeStatus` | renvoie une chaîne d'erreur → HTTP 200 avec `errors[]`, pas d'insert |
| 4 | `db->transaction`: `createCheckIn` (INSERT) | violation index unique `attendee_check_ins_unique_live_scan` → `QueryException` **non catchée** → **HTTP 500** (cf. S5) |
| 5 | si `action = CHECK_IN_AND_MARK_ORDER_AS_PAID` | `MarkOrderAsPaidService::markOrderAsPaid` → `ResourceConflictException` si l'Order n'est pas `AWAITING_OFFLINE_PAYMENT` |

---

## 2. Conditions préalables (niveau requête, avant la boucle)

| Condition | Code | Résultat si non satisfaite |
|---|---|---|
| Liste existe (`check_in_lists.short_id`) | `CheckInListDataService::getCheckInList` `:74-87` | `CannotCheckInException('Check-in list not found')` → 409 |
| `expires_at` non dépassé (UTC) | `validateCheckInListIsActive` `:75-77` | `'Check-in list has expired'` → 409 |
| `activates_at` non futur (UTC) | `:79-81` | `'Check-in list is not active yet'` → 409 |
| Tous les `public_id` résolvent à exactement 1 attendee | `getAttendees` `:59-66` | `'Invalid attendee code detected: …'` → 409 (⚠️ collision S4 déclenche ceci) |

---

## 3. Matrice par attendee (étapes 1→4)

Réglages influents : `S_off` = `event_settings.allow_orders_awaiting_offline_payment_to_check_in`.
Actions possibles : `CHECK_IN` (défaut) · `CHECK_IN_AND_MARK_ORDER_AS_PAID` · `CHECK_OUT` (non couvert ici).

| # | Statut attendee | Order (dérivé) | Produit ∈ liste ? | 1er ou 2e scan | Action | `S_off` | Résultat | Code responsable |
|---|---|---|---|---|---|---|---|---|
| 1 | `ACTIVE` | `COMPLETED` | oui | 1er | CHECK_IN | — | ✅ **check-in enregistré** (`recorded`) | `createCheckIn` `:251` |
| 2 | `ACTIVE` | `COMPLETED` | oui | 2e (même liste) | CHECK_IN | — | ⚠️ `errors[]` « already checked in », **pas de doublon**, HTTP 200 | `getExistingCheckIn` `:184` |
| 3 | `ACTIVE` | `COMPLETED` | oui | 2e **concurrent** (course) | CHECK_IN | — | ❌ **HTTP 500** (QueryException 23505 non catchée) — intégrité OK, UX KO | index `attendee_check_ins_unique_live_scan` + `Handler.php:81` |
| 4 | `ACTIVE` | `COMPLETED` | **non** | 1er | CHECK_IN | — | ❌ 409 « not allowed to check in using this check-in list » — **tout le lot avorté** | `verifyAttendeeBelongsToCheckInList` `:35` |
| 5 | `CANCELLED` | `CANCELLED` (ou billet annulé) | oui | 1er | CHECK_IN | — | ❌ `errors[]` « ticket is cancelled », pas d'insert | `validateAttendeeStatus` `:226` |
| 6 | `AWAITING_PAYMENT` | `AWAITING_OFFLINE_PAYMENT` | oui | 1er | CHECK_IN | `false` | ❌ `errors[]` « order is awaiting payment » | `:232-239` |
| 7 | `AWAITING_PAYMENT` | `AWAITING_OFFLINE_PAYMENT` | oui | 1er | CHECK_IN | `true` | ✅ check-in enregistré (statut attendee inchangé, order toujours en attente) | `validateAttendeeStatus` renvoie `null` |
| 8 | `AWAITING_PAYMENT` | `AWAITING_OFFLINE_PAYMENT` | oui | 1er | CHECK_IN_AND_MARK_ORDER_AS_PAID | `false` | ❌ `errors[]` « cannot be marked as paid. Please check your event settings » | `:241-245` |
| 9 | `AWAITING_PAYMENT` | `AWAITING_OFFLINE_PAYMENT` | oui | 1er | CHECK_IN_AND_MARK_ORDER_AS_PAID | `true` | ✅ check-in **+** `markOrderAsPaid` : order→`COMPLETED`/`PAYMENT_RECEIVED`, attendees `AWAITING_PAYMENT`→`ACTIVE`, facture `PAID`, `OrderApplicationFee` `OFFLINE`, mail récap client | `processIndividualCheckIn:200-205` + `MarkOrderAsPaidService` |
| 10 | `AWAITING_PAYMENT` | Order **`COMPLETED`** (incohérent) | oui | 1er | CHECK_IN_AND_MARK_ORDER_AS_PAID | `true` | ❌ `ResourceConflictException('Order is not awaiting offline payment')` dans la transaction → remonte en `CannotCheckInException`? **NON** — `ResourceConflictException` n'est pas `CannotCheckInException` → **HTTP 500** | `MarkOrderAsPaidService.php:78-80` |
| 11 | `ACTIVE` | `COMPLETED` | oui | 1er | CHECK_IN | — | liste expirée entre-temps ? déjà filtré étape B (niveau requête) | `validateCheckInListIsActive` |
| 12 | code `public_id` inconnu | — | — | — | — | — | ❌ 409 « Invalid attendee code detected » — **tout le lot avorté** | `getAttendees:59` |
| 13 | 2 attendees, `public_id` en collision (S4) | — | — | — | — | — | ❌ 409 « Invalid attendee code » — **les 2 porteurs non-scannables** | `getAttendees:59` |
| 14 | `ACTIVE` | `COMPLETED` | oui | après un CHECK_OUT (soft-delete du check-in) | CHECK_IN | — | ✅ nouveau check-in possible : l'index unique est **partiel** `WHERE deleted_at IS NULL` | migration DIGIT `2026_07_18_000001` |

### Statut de l'Order — remarque

`validateAttendeeStatus` teste le **statut de l'attendee**, pas directement `orders.status`. La correspondance normale :

| `orders.status` | `attendees.status` (attendu) |
|---|---|
| `COMPLETED` | `ACTIVE` |
| `AWAITING_OFFLINE_PAYMENT` | `AWAITING_PAYMENT` |
| `CANCELLED` | `CANCELLED` |
| `RESERVED` / `PAYMENT_FAILED` | attendee absent ou `AWAITING_PAYMENT` |

Une désynchronisation attendee/order (ligne 10) n'est pas gérée proprement → 500.

---

## 4. Autorisation & débit de l'endpoint public

| Aspect | Constat | Preuve |
|---|---|---|
| Authentification | **Aucune** (groupe `/public`, pas de `auth:api`) | `routes/api.php:493, 540` |
| Secret d'accès | `check_in_lists.short_id` = `IdHelper::shortId('cil', 13)` ≈ 74 bits, CSPRNG | `IdHelper.php:17-20` |
| Rate limit | Global `throttle:api` = 180/min, clé = `user?->id ?: ip` | `Kernel.php:70`, `RouteServiceProvider.php:27` |
| Rate limit dédié | **Aucun** sur `…/check-ins`, `…/attendees` | `routes/api.php:537-541` |
| Cloisonnement | `getCheckInList` par `short_id` → `event_id` ; `verifyAttendeeBelongsToCheckInList` par `product_id ∈ liste.products` | `CheckInListDataService:33-41` |
| Fuite d'info via `GET …/attendees/{public_id}` | renvoie données attendee (nom, email, statut) sans auth, mais nécessite le `short_id` de liste | `routes/api.php:539` |
| IP enregistrée | `attendee_check_ins.ip_address = $request->ip()` | `CreateAttendeeCheckInPublicAction:31`, `createCheckIn:261` |

---

## 5. Condition minimale « une vente guichet est scannable »

Après lecture, la condition **exacte et suffisante** :

1. `attendees.status = ACTIVE` — obtenu par `CreateAttendeeHandler` (Order `COMPLETED`) ;
2. `attendees.product_id` ∈ `check_in_lists.products` d'**au moins une** liste de l'événement ;
3. cette liste dans sa fenêtre `activates_at`/`expires_at` ;
4. le personnel dispose du `check_in_list.short_id` (URL du scanner).

**Rien d'autre.** Pas de signature, pas de vérification de prix, pas de vérification de l'email au scan.

Corollaire (décision D7) : **un produit non rattaché à une check-in list est invendable en pratique au guichet** — le billet existe mais est rejeté au scan (ligne 4). L'orchestrateur Kiosk doit le détecter **avant** la vente.

---

## 6. Test de compatibilité de bout en bout — SPÉCIFIÉ, NON EXÉCUTÉ

> À exécuter uniquement dans le conteneur `docker/development` (Postgres de dev isolé), avec des données **fictives**. Non lancé dans cette mission (pas d'accès conteneur confirmé, règle « pas de migration »).

```
tests/Feature/BoxOffice/NativeCheckInCompatibilityTest.php  (à créer)

1.  Créer account + user ORGANIZER + event de test (devise EUR)
2.  Créer product TICKET "Pass Test" + product_price 10.00, quantity_available = 5
3.  Créer check_in_list "Entrée principale", activates_at = now-1h, expires_at = now+8h
    associer product "Pass Test" à la liste (product_check_in_lists)
4.  Appeler CreateAttendeeHandler::handle(CreateAttendeeDTO{
       first_name:"Test", last_name:"Guichet", email:"test+guichet@example.invalid",
       product_id, product_price_id, amount_paid: 10.00, send_confirmation_email: false,
       locale:"fr", event_id })
    -> Assert: attendee ACTIVE, order COMPLETED, order.public_id != null,
               product_prices.quantity_sold == 1
5.  Lire attendee.public_id (= identifiant officiel, celui du QR natif et du PDF)
6.  POST /public/check-in-lists/{list.short_id}/check-ins {attendees:[{public_id, action:"CHECK_IN"}]}
    -> Assert: 200, attendeeCheckIns[0] présent, errors == []
    -> Assert DB: 1 ligne attendee_check_ins (attendee_id, check_in_list_id, ip_address, event_id)
7.  Rejouer le POST identique (séquentiel)
    -> Assert: 200, attendeeCheckIns[0] == le même, errors[0] ~ "already checked in"
    -> Assert DB: toujours 1 seule ligne
8.  (option S5) Rejouer 2× en parallèle -> documenter: 1×200 + 1×500 (comportement actuel)

Critères de réussite du test de compatibilité :
- étape 4-6 : une vente guichet native est scannable sans code supplémentaire ✅
- étape 7 : le 2e scan séquentiel est neutralisé proprement ✅
- étape 8 : le 2e scan concurrent renvoie 500 (limite connue, cf. S5) — à corriger côté Kiosk si le Kiosk pilote le scan
```
