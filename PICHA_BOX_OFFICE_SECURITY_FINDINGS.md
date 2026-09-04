# PICHA Box Office — Constats de sécurité du flux manuel

Date : 3 septembre 2026 · Méthode : lecture de code, lecture du schéma. Aucun test exécuté (pas de PHP/artisan dans l'environnement d'analyse ; conteneur non démarré). Les tests ci-dessous sont **spécifiés**, pas exécutés.

Sévérité : **CRITIQUE** (bloque le développement Kiosk tant que non tranché) · **ÉLEVÉ** · **MOYEN** · **FAIBLE / INFO**

---

## Tableau de synthèse

| # | Constat | Sévérité | Statut audit initial | Statut vérifié |
|---|---|---|---|---|
| S1 | `amount_paid` client jamais confronté au prix serveur | CRITIQUE | rapporté | **CONFIRMÉ** |
| S2 | Aucun verrou de stock — survente du dernier billet | CRITIQUE | rapporté | **CONFIRMÉ (par lecture)** — test à exécuter |
| S3 | Aucune idempotence sur la création manuelle | CRITIQUE | rapporté | **CONFIRMÉ** |
| S4 | `attendees.public_id` sans contrainte d'unicité en base | ÉLEVÉ | non rapporté | **NOUVEAU — CONFIRMÉ** |
| S5 | Double check-in concurrent → HTTP 500 (pas d'erreur métier) | MOYEN | partiellement rapporté | **CONFIRMÉ** |
| S6 | `taxes_and_fees` fournis et non validés par le client | ÉLEVÉ | rapporté | **CONFIRMÉ** |
| S7 | Espace d'identifiant QR ≈ 34–36 bits, endpoint check-in public | FAIBLE/INFO | « QR non signé » rapporté | **NUANCÉ** — voir §S7 |
| S8 | `product_price_id` : valeur brute du DTO utilisée pour le stock et l'attendee | MOYEN | rapporté | **CONFIRMÉ** |
| S9 | Retrait de la mention AGPL « Powered by Hi.Events » | ÉLEVÉ (juridique) | non rapporté | **NOUVEAU — CONFIRMÉ** |
| S10 | Pas de rate-limit spécifique sur les endpoints check-in publics | FAIBLE | non rapporté | **CONFIRMÉ** (limite globale 180/min/IP) |

---

## S1 — Prix non validé côté serveur — CRITIQUE

**Preuve**
- `backend/app/Http/Request/Attendee/CreateAttendeeRequest.php:20` : `'amount_paid' => ['required', ...RulesHelper::MONEY]`.
- `backend/app/Validators/Rules/RulesHelper.php:7` : `MONEY = ['gte:0', 'numeric', 'decimal:0,2', 'max:999999999999']`.
- `backend/app/Services/Application/Handlers/Attendee/CreateAttendeeHandler.php`
  - `:116` `Money::of($attendeeDTO->amount_paid, $event->getCurrency())` → total de l'Order.
  - `:127-129` `payment_status` = `NO_PAYMENT_REQUIRED` si `amount_paid == 0`, sinon `PAYMENT_RECEIVED`.
  - `:209-213` `OrderItem.price` = `OrderItem.total_before_additions` = `$attendeeDTO->amount_paid` **tel quel**.
- **Aucune lecture de `product_prices.price`** dans tout le handler. `getProductPriceId()` (`:141`) ne valide que l'appartenance de l'ID au produit, pas le montant.

**Ce qui devrait faire autorité (sans modifier le code) :** `product_prices.price` pour le `product_price_id` résolu, + le rollup de taxes calculé serveur (`TaxAndFeeRollupService`), le `amount_paid` client servant uniquement de « montant encaissé déclaré ».

**Caractérisation attendue (test à écrire — non exécuté)**

| Entrée | Comportement actuel attendu |
|---|---|
| `amount_paid` = 1.00 pour un billet à 50.00 | Order créé, `payment_status = PAYMENT_RECEIVED`, `total_gross = 1.00` |
| `amount_paid` = 0 pour un billet payant | Order créé, `payment_status = NO_PAYMENT_REQUIRED` — **billet gratuit silencieux** |
| `amount_paid` = -5 | **Rejeté** par `gte:0` |
| `amount_paid` = 999999999.99 | Accepté (`max` très haut) |
| `product_price_id` d'un autre palier (moins cher) du même produit | Accepté — `getProductPriceId` ne vérifie que l'appartenance au produit |
| `product_price_id` d'un autre produit / autre événement | **Rejeté** — `InvalidProductPriceId` (l'ID n'est pas dans `$product->getProductPrices()`) |
| Devise : le handler force `$event->getCurrency()` | Le client ne peut pas imposer une devise |

**Impact Kiosk** : un agent (ou un appel API avec un token ORGANIZER volé) peut émettre des billets à prix arbitraire ; aucune trace d'écart prix attendu / prix encaissé. **À corriger dans l'orchestrateur Kiosk (validation serveur), pas dans `CreateAttendeeHandler` (upstream).**

---

## S2 — Survente : aucun verrou sur le stock — CRITIQUE

**Preuve**
- `CreateAttendeeHandler.php:84-93` : `getQuantityRemainingForProductPrice()` (SELECT) puis test `<= 0`.
- `backend/app/Repository/Eloquent/ProductRepository.php:60` `getQuantityRemainingForProductPrice()` : requête de lecture simple, **sans `lockForUpdate`**.
- `backend/app/Services/Domain/Product/ProductQuantityUpdateService.php:28-43` `increaseQuantitySold()` : `UPDATE product_prices SET quantity_sold = quantity_sold + 1` via `DB::raw`, dans une **transaction imbriquée distincte** (`:30`), **sans verrou ni condition** sur la capacité restante.
- La transaction externe (`CreateAttendeeHandler::handle`, `:64`) englobe la lecture et l'écriture, mais **Postgres READ COMMITTED par défaut** : deux transactions concurrentes lisent chacune `quantity_remaining = 1`, passent le test, et incrémentent toutes les deux.
- Aucune contrainte `CHECK (quantity_sold <= quantity_available)` dans `database/migrations/schema.sql` ni migration ultérieure trouvée.

**Test reproductible à écrire (NON exécuté — nécessite conteneur `docker/development` avec base de test isolée)**

```
tests/Unit/BoxOffice/OversellCharacterizationTest.php  (à créer, DatabaseTransactions)
  - Arrange : event, product TICKET, product_price avec quantity_available = 1, quantity_sold = 0
  - Act : lancer 2 appels CreateAttendeeHandler::handle() « en parallèle »
          (2 connexions PDO distinctes, ou pcntl_fork, ou 2 requêtes HTTP concurrentes via un test Feature)
  - Assert (attendu = ÉCHEC de la protection) :
      * 2 attendees ACTIVE créés
      * product_prices.quantity_sold = 2  > quantity_available = 1
```

Variante déterministe sans parallélisme réel : ouvrir une transaction A, y lire le stock, puis dans une transaction B (autre connexion) exécuter tout le handler et committer, puis reprendre A → A voit encore l'ancien `quantity_sold`.

**Éléments observés**
- Lignes lues : `product_prices` (via `getQuantityRemainingForProductPrice`), `capacity_assignments`.
- Lignes modifiées : `product_prices.quantity_sold`, `capacity_assignments.used_capacity`, `orders`, `order_items`, `attendees`.
- Transactions : 1 externe (`DatabaseManager::transaction` dans le handler) + 1 imbriquée dans `increaseQuantitySold` (savepoint).
- Isolation : par défaut `READ COMMITTED`.
- Contraintes DB : aucune sur la capacité.

**Comparaison des remèdes (sans implémenter)**

| Option | Description | Déjà utilisé dans le dépôt ? | Coût | Remarque |
|---|---|---|---|---|
| Verrou pessimiste | `SELECT … FROM product_prices WHERE id = ? FOR UPDATE` avant test+incrément, dans la transaction du handler | **Non** (le repo n'expose pas `lockForUpdate`) | moyen | simple à raisonner ; sérialise les ventes d'un même palier |
| UPDATE atomique conditionnel | `UPDATE product_prices SET quantity_sold = quantity_sold + 1 WHERE id = ? AND (quantity_available IS NULL OR quantity_sold < quantity_available)` puis vérifier `affectedRows == 1` | Partiellement : `GREATEST(0, …)` déjà utilisé pour la décrémentation (`ProductQuantityUpdateService:55`) | faible | pas de verrou explicite, gère la capacité produit ; ne couvre pas seul les `capacity_assignments` |
| Réservation Hi.Events | `orders.reserved_until` + statut `RESERVED` + `CheckoutSessionManagementService` (flux public) | Oui, mais **flux public uniquement** (`TransitionOrderToOfflinePaymentHandler:50`) | élevé | réutiliserait un mécanisme éprouvé mais lourd pour un guichet |
| `CapacityChangedEvent` / capacity assignments | déjà déclenché à l'achat public | Oui | — | ne protège pas la course, sert au recalcul |

**Recommandation (fondée sur ces constats)** : dans l'orchestrateur Kiosk, entourer test-de-stock + `increaseQuantitySold` d'un **verrou pessimiste sur la ligne `product_prices`** (`lockForUpdate`) **dans la même transaction**, en complément d'un `UPDATE … WHERE quantity_sold < quantity_available` défensif. Ne pas modifier `CreateAttendeeHandler` (partagé avec l'édition d'attendee et l'upstream).

---

## S3 — Aucune idempotence — CRITIQUE

**Preuve**
- `grep -rn "idempoten" app/` : **0 résultat dans `app/`**. Le seul mécanisme (`ScanIdempotencyService`, `Idempotency-Key`, Redis) vit dans `backend/modules/digit/src/Scan/` et `…/Bracelets/` — **module DIGIT, hors périmètre Kiosk** (décision Jo).
- `CreateAttendeeAction` / `CreateAttendeeHandler` : aucun middleware, aucune clé métier, aucune contrainte unique métier. La seule unicité est `orders.public_id` / `orders.short_id` (générés aléatoirement à chaque appel — n'aide pas).
- Pas de `throttle` sur `POST /events/{event_id}/attendees` (route dans le groupe `auth:api`, seulement `throttle:api` global 180/min).

**Effets d'une répétition (raisonnés, non testés)**

| Scénario | Résultat |
|---|---|
| Double-clic / double-submit | **2 Orders + 2 Attendees + `quantity_sold += 2`**. Le frontend `CreateAttendeeModal` désactive le bouton via `mutation.isPending` mais ne protège pas contre 2 requêtes déjà parties. |
| 2 requêtes identiques concurrentes | idem — aucune sérialisation |
| Timeout client après commit serveur | l'Order est créé ; le client, ne recevant pas la réponse, ré-essaie → doublon |
| Rejeu après erreur 5xx | si l'erreur est survenue **après** le commit (ex. job mail), le rejeu duplique |
| Redémarrage worker | le handler est **synchrone dans la requête HTTP** (pas un job) — un redémarrage worker n'affecte que `SendOrderDetailsEmailJob` / `UpdateEventStatisticsJob` (rejouables mais : mail en double possible) |

**Impact Kiosk** : sur un réseau événementiel instable, doublons de billets et de stock quasi garantis. **Capacité manquante à concevoir** (table de projection + clé unique — algorithme non choisi ici).

---

## S4 — `attendees.public_id` sans unicité en base — ÉLEVÉ (NOUVEAU)

**Preuve**
- `database/migrations/schema.sql` :
  - `orders` : `constraint orders_pk unique (public_id)` (ligne 348) ✅
  - `attendees` : `public_id varchar not null` (ligne 599) — **aucune contrainte `unique`**. Seuls index : `idx_attendees_public_id_trgm` (GIN trigram) et `idx_attendees_public_id_lower` (btree sur `lower(public_id)`, **non unique**).
- `grep -rn "public_id" database/migrations/*.php | grep -i uniq` : **0 résultat** → aucune migration n'ajoute l'unicité par la suite.
- `backend/app/Helper/IdHelper.php:22-25` : `publicId()` = `Str::upper('a' . '-' . Str::random(7))`. **Aucune vérification de collision, aucune boucle de retry.**
- `Str::random` (Laravel 12) = CSPRNG (`random_bytes`), alphabet base62 ; après `Str::upper`, alphabet effectif ≈ 36 symboles, distribution biaisée → **entropie ≈ 34–36 bits** sur 7 caractères.

**Conséquence d'une collision**
- `CheckInListDataService::getAttendees()` (`:54-66`) fait `findWhereIn(PUBLIC_ID, [$id])` **sans filtre `event_id`**. Si 2 attendees (même événement ou événements différents) partagent un `public_id`, la requête renvoie 2 lignes pour 1 code → `count($attendees) !== count($publicIds)` → `CannotCheckInException('Invalid attendee code detected')`.
- **Résultat : les DEUX porteurs deviennent non-scannables.** Déni de service involontaire sur l'accès.

**Probabilité** : borne des anniversaires — ~50 % de chance d'**au moins une** collision à ≈ 2¹⁸ ≈ **260 000 attendees** dans la table (tous événements confondus, sur la durée de vie de la plateforme). Pour un festival isolé (dizaines de milliers), risque faible ; cumulé multi-événements, réaliste.

**Remède (recommandation, = migration → hors périmètre de cette mission, à planifier)** : `CREATE UNIQUE INDEX CONCURRENTLY attendees_public_id_unique ON attendees (public_id) WHERE deleted_at IS NULL` + boucle de retry sur collision dans le générateur, **ou** allonger `Str::random` à 10–13 pour l'attendee. À valider : impact sur les billets déjà émis (aucun si l'index passe).

---

## S5 — Double check-in concurrent → HTTP 500 — MOYEN

**Preuve**
- `CreateAttendeeCheckInService.php` :
  - `:58` `fetchExistingCheckIns()` lit les check-ins **avant** la boucle.
  - `:184-191` test « déjà scanné » **en mémoire** (`getExistingCheckIn`).
  - `:197` `db->transaction(fn => createCheckIn(...))` — insert simple, **aucun `try/catch` sur violation d'unicité**.
- `modules/digit/database/migrations/2026_07_18_000001_add_unique_constraint_to_attendee_check_ins.php` : index **partiel unique** `attendee_check_ins_unique_live_scan (attendee_id, check_in_list_id) WHERE deleted_at IS NULL`.
- `app/Exceptions/Handler.php:81-90` : `render()` ne traite que `ResourceNotFoundException` ; toute `QueryException` (SQLSTATE 23505) tombe dans `parent::render()` → **HTTP 500** + capture Sentry (`:43-67`).

**Conséquence** : l'intégrité est garantie (jamais 2 lignes de check-in), **mais** :
- 2 scans quasi simultanés du même billet (2 agents, 2 appareils) → l'un réussit, l'autre reçoit **500** au lieu de « déjà scanné » (409).
- Bruit Sentry.

**Test à écrire (NON exécuté)** : 2 requêtes `POST /public/check-in-lists/{shortId}/check-ins` concurrentes sur le même `public_id` → attendu : 1 × 200, 1 × 500 (au lieu de 1 × 200 + 1 × réponse `errors[]`).

**Remède** : dans l'orchestrateur / un wrapper Kiosk, `catch (QueryException $e)` sur `23505` → renvoyer le résultat « déjà scanné » idempotent. Ne concerne le Kiosk **que si** le Kiosk pilote lui-même le check-in (parcours vertical, étape 7).

---

## S6 — `taxes_and_fees` fournis par le client — ÉLEVÉ

**Preuve**
- `CreateAttendeeRequest.php:22-24` : `taxes_and_fees.*.tax_or_fee_id` + `taxes_and_fees.*.amount` (**montant fourni par le client**).
- `CreateAttendeeHandler::calculateTaxesAndFees()` (`:162-188`) : vérifie que le `tax_or_fee_id` **existe**, mais **utilise le `amount` du client** (`processTaxesAndFees` `:190-201` → `addToRollUp($taxOrFee, $DTO->amount)`).
- Le montant de taxe n'est **pas recalculé** à partir du taux configuré (`taxes_and_fees.rate`).

**Impact** : totaux de taxes/frais arbitraires → factures et exports comptables faux. Même remède que S1 (recalcul serveur dans l'orchestrateur).

---

## S7 — Identifiant QR : falsifiabilité — FAIBLE / INFO (NUANCÉ)

L'audit disait « QR non signé → un `public_id` connu suffit à scanner ». **Nuance après lecture :**

| Élément | Constat |
|---|---|
| Génération | CSPRNG (`Str::random` → `random_bytes`), **non séquentiel, non prédictible** |
| Entropie | ≈ 34–36 bits (7 car., alphabet ~36 biaisé après `Str::upper`) |
| Pour check-in il faut AUSSI | le `check_in_list.short_id` = `Str::random(13)` ≈ **74 bits** (détenu par le staff, dans l'URL du scanner) |
| Endpoint public | `POST /public/check-in-lists/{short_id}/check-ins` — pas d'auth, mais throttle global **180/min/IP** |
| Vérif produit | `verifyAttendeeBelongsToCheckInList` — le billet doit être sur la liste |

**Modèle de menace réaliste** :
- Sans le `check_in_list.short_id` (74 bits) : impossible.
- Avec un `short_id` fuité + force brute des `public_id` à 180/min : balayage complet de 2³⁵ ≈ 350 j ; ~50 % de touche en ~175 j. **Non exploitable sur la durée d'un événement.**
- Le vrai risque : quelqu'un qui **voit le QR/billet d'un tiers** (photo, PDF transféré) peut le faire scanner à sa place — **inhérent au modèle Hi.Events**, pas au guichet. Un badge imprimé au guichet a la même propriété.

**Conclusion** : le QR **n'est pas « falsifiable »** au sens forge à partir de rien. Il est **rejouable/transférable** (comme tout billet Hi.Events). Si PICHA veut des badges infalsifiables → signature HMAC (le module Bracelets DIGIT le fait déjà : `c08499bb`), **décision produit**, hors MVP.

---

## S8 — `product_price_id` : valeur brute du DTO — MOYEN

**Preuve** — `CreateAttendeeHandler.php` :
- `:82` et `:95` calculent `$productPriceId` via `getProductPriceId()` (valeur **résolue**, validée).
- **mais** `:227` `createAttendee()` écrit `AttendeeDomainObjectAbstract::PRODUCT_PRICE_ID => $attendeeDTO->product_price_id` (**valeur brute**).
- **et** `:242` `increaseQuantitySold(priceId: $attendeeDTO->product_price_id)` (**valeur brute**).
- Le `Request` impose `product_price_id` (`['int','nullable','required']` → `required` l'emporte : présent et non-null). Donc en pratique brute == résolue.
- **Risque résiduel** : un futur appelant (l'orchestrateur Kiosk) qui s'appuierait sur la résolution `getProductPriceId` (paliers, prix par défaut) sans envoyer `product_price_id` verrait l'attendee et le stock décrémentés sur `null` → incohérence. **L'orchestrateur doit toujours envoyer `product_price_id` explicitement.**

---

## S9 — Retrait de la mention AGPL — ÉLEVÉ (juridique, NOUVEAU)

**Preuve** — `git diff backend/resources/views/vendor/mail/html/message.blade.php` :
```
- © {{ date('Y') }} … | Powered by <a … href="https://hi.events…">Hi.Events</a>
+ © {{ date('Y') }} … | Powered by PICHA AI
```
Le commentaire Blade juste au-dessus (conservé) : *« In accordance with Section 7(b) of the AGPL, we ask that you retain the "Powered by Hi.Events" notice. If you wish to remove this notice, a commercial license is available at: https://hi.events/licensing »*.

Les env contiennent `VITE_I_HAVE_PURCHASED_A_LICENCE` — **valeur non inspectée / non reproduite**.

**Impact** : si PICHA n'a pas de licence commerciale Hi.Events, ce retrait viole la clause de préservation de mention (AGPL §7(b)). **Décision juridique requise (Jo)** avant de committer ce diff — sans rapport direct avec le Kiosk mais dans la même baseline.

---

## S10 — Rate-limit des endpoints check-in publics — FAIBLE

**Preuve**
- `routes/api.php:537-541` : les routes `/public/check-in-lists/{short_id}/…` **n'ont pas** de `->middleware('throttle:…')` dédié (contrairement à `waitlist` `throttle:10,1` ou `self-service-*`).
- `app/Providers/RouteServiceProvider.php:27-30` : limiteur global `api` = `config('app.api_rate_limit_per_minute')` (**180**) `->by(user?->id ?: ip)`.
- `app/Http/Kernel.php:70` : `'api' => [ThrottleRequests::class.':api', …]` appliqué à tout `routes/api.php`.

**Constat** : protégé à 180/min/IP, pas davantage. Suffisant compte tenu de S7 (le `short_id` de liste est le vrai secret), mais un `throttle` plus strict (ex. `30,1`) sur `POST …/check-ins` et `GET …/attendees` serait prudent. **Décision d'infra, pas bloquante.**

---

## Ce qui n'a PAS pu être déterminé

| Point | Raison |
|---|---|
| Exécution réelle des tests S1/S2/S5 | Pas de PHP/artisan dans l'environnement d'analyse ; conteneur `docker/development` non démarré ; règle « pas de migration ». |
| Valeur de `VITE_I_HAVE_PURCHASED_A_LICENCE` | Non inspectée volontairement (fichier d'env — pas de reproduction de contenu). |
| Comportement exact de `capacity_assignments` sous course (S2) | Même code sans verrou que `product_prices` ; effet identique supposé, non prouvé par test. |
