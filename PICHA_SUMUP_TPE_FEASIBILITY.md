# PICHA — TPE SumUp avec confirmation automatique au Guichet — Audit de faisabilité

Date : 6 septembre 2026 · **Aucun code applicatif écrit.** Document d'audit, indépendant de D23 et du
reste du Kiosk v2. Approfondit le point **D19b** ouvert dans `PICHA_KIOSK_V2_DECISIONS.md`.
Modèle : `PICHA_BOX_OFFICE_DECISIONS_REQUIRED.md`.
Aucune décision n'est prise ici. Chaque ligne « DÉCISION REQUISE » attend une validation explicite de Jo / PICHA.

Légende impact : **[Infra]** déploiement/DNS/proxy/compte marchand · **[Code]** volume de dev ·
**[Schéma]** migration · **[UX]** parcours opérateur · **[Sécu]** · **[Compta]** rapports/rapprochement.

Catégories (par point) :
- **CONFIRMÉ** — établi par lecture du code du dépôt ou de la doc SumUp officielle.
- **NON DÉTERMINÉ** — dépend d'infos hors dépôt (compte marchand PICHA, zone, parc matériel) à obtenir avant de trancher.
- **DÉCISION REQUISE** — choix métier/technique à acter avec Jo.

---

## Méthode

### Code du dépôt lu

- `backend/app/DomainObjects/Enums/PaymentProviders.php`
- `backend/app/DomainObjects/Enums/BoxOfficePaymentMethod.php`, `.../Status/BoxOfficeSaleStatus.php`, `.../Status/OrderStatus.php`
- `backend/app/Services/Application/Handlers/BoxOffice/CreateBoxOfficeSaleHandler.php` (+ DTO, Action)
- `backend/database/migrations/2026_09_05_000000_create_box_office_sales_table.php`
- `backend/app/Services/Application/Handlers/Attendee/CreateAttendeeHandler.php` (création Order/Attendee)
- `backend/app/Http/Actions/Common/Webhooks/StripeIncomingWebhookAction.php`
- `backend/app/Services/Application/Handlers/Order/Payment/Stripe/IncomingWebhookHandler.php`
- `backend/app/Services/Domain/Payment/Stripe/EventHandlers/PaymentIntentSucceededHandler.php`
- `backend/app/Services/Application/Handlers/Order/TransitionOrderToOfflinePaymentHandler.php`
- `backend/app/Services/Application/Handlers/Order/MarkOrderAsPaidHandler.php` + `backend/app/Services/Domain/Order/MarkOrderAsPaidService.php`
- `backend/app/Http/Actions/Orders/MarkOrderAsPaidAction.php`
- `backend/routes/api.php` (groupes `auth:api` / `/public`, route `/public/webhooks/stripe`)

### Doc SumUp officielle consultée (Context7 — `developer.sumup.com`)

- Terminal Payments → **Cloud API** : `POST /v0.1/merchants/{merchant_code}/readers/{reader_id}/checkout`, `.../terminate`
- « Receive Transaction Results » : webhook `return_url`, événement `solo.transaction.updated`, payload
- « Webhooks → Handling a Webhook » (recommandation de vérification out-of-band)
- « Terminal Payments → Market Availability »
- SDK PHP `sumup/sumup-ecom-php-sdk` (référence d'intégration serveur)

---

## §1 — Ce que le « mode Reader » de SumUp est réellement (CONFIRMÉ)

### 1.1 Le bon produit

Le mode qui permet une automatisation serveur → terminal → confirmation est **Terminal Payments /
Cloud API**, et il fonctionne **uniquement avec le lecteur SumUp Solo** (terminal autonome, avec écran
et connectivité propre). Sont **hors sujet** :

- l'**app mobile SumUp** + lecteur *Air* / lecteur tethered au téléphone : aucune API de déclenchement,
  aucun webhook — c'est le constat de la mission ;
- le **Terminal SDK** SumUp (SDK embarqué dans *votre propre* app Android sur le terminal) : autre
  chantier (app native sur le Solo), non retenu ici.

Donc : **la faisabilité dépend d'abord de la possession de lecteurs SumUp *Solo*.** Un parc d'Air /
app mobile ne permet rien.

### 1.2 Séquence SumUp Cloud API (CONFIRMÉ par la doc)

1. **Appairage** préalable du Solo au compte marchand (via Cloud API / dashboard). Le back récupère le
   `reader_id` (`GET /v0.1/merchants/{merchant_code}/readers`).
2. **Déclenchement** : `POST .../readers/{reader_id}/checkout` avec `total_amount` (`{currency, minor_unit, value}`)
   et `return_url` (URL **HTTPS** de webhook). La réponse HTTP est **un simple accusé de réception** :
   `{ "data": { "client_transaction_id": "<uuid>" } }`. Elle ne dit **pas** que le paiement a réussi.
   - Le terminal doit être **en ligne** ; sinon le checkout est rejeté.
   - Après acceptation, SumUp a **60 s** pour démarrer le paiement sur le terminal ; pendant ce temps
     tout autre checkout sur le même terminal est rejeté.
3. **Le client tape sa carte / PIN** sur le Solo. Durée non bornée côté PICHA.
4. **Résultat asynchrone** : SumUp envoie un **POST sur `return_url`**, événement
   `solo.transaction.updated`, avec :
   ```json
   {
     "id": "…",
     "event_type": "solo.transaction.updated",
     "payload": {
       "client_transaction_id": "…",   // corrélation avec l'étape 2 (transaction_id est déprécié)
       "merchant_code": "M…",
       "status": "successful" | "failed",
       "failure_reason": "…"           // uniquement si failed, texte libre
     },
     "timestamp": "…"
   }
   ```
5. **Annulation** : `POST .../readers/{reader_id}/terminate` — asynchrone, **sans confirmation**, ne
   marche que si le terminal est en ligne et en attente d'action porteur. Si la transaction est
   effectivement interrompue et qu'un `return_url` était fourni, le webhook arrive avec `status = failed`.

### 1.3 Sécurité du webhook — **la prémisse de la mission est à corriger**

La mission parle d'un « endpoint de réception de webhook sécurisé (vérification de signature) », sur le
modèle Stripe. **Or la doc SumUp du webhook Solo ne décrit aucun mécanisme de signature** (pas
d'équivalent de `Stripe-Signature` / `Webhook::constructEvent`). La recommandation officielle de SumUp
est explicite : *« it is a security best practice to verify the event by calling the relevant SumUp API
to confirm the status change »*.

Conséquence : la sécurité de l'endpoint PICHA ne peut **pas** reposer sur une vérification de signature.
Elle doit reposer sur **trois** garde-fous cumulés :

| Garde-fou | Détail |
|---|---|
| **`return_url` non devinable, à usage unique** | PICHA génère un token à forte entropie par checkout, le stocke sur la vente en attente, et le met dans le path/query du `return_url` (`https://api.picha.fr/public/webhooks/sumup/{token}`). Un webhook sans token valide → rejeté. |
| **Confirmation *out-of-band*** | À réception du webhook, ne **jamais** faire confiance au `status`/montant du corps. Rappeler l'API SumUp authentifiée (`GET .../transactions?...` par `client_transaction_id`) avec la clé API pour lire le **vrai** statut **et le vrai montant**, et ne finaliser la vente que si `successful` **et** montant == prix serveur du billet. |
| **Allowlist IP / rate-limit** | Si SumUp publie des plages d'IP sortantes → allowlist au niveau proxy. Sinon au minimum `throttle` sur la route. |

C'est une **différence structurelle avec Stripe** : le flux SumUp impose systématiquement **un appel
serveur → SumUp de confirmation** en plus du webhook. Le webhook n'est qu'un *réveil*.

### 1.4 Disponibilité régionale (CONFIRMÉ que c'est conditionnel — valeur NON DÉTERMINÉE)

Doc SumUp, « Market Availability » : *« Reader availability is region-specific, and merchants should
verify the SumUp webshop in their country before planning a rollout. Once a reader processes its first
transaction, it becomes locked to the merchant account's country. »*

Mayotte (976) est un DOM français, zone euro, UE — mais **la disponibilité commerciale du Solo, la
distribution du matériel et l'activation de la Cloud API pour un compte rattaché à Mayotte ne sont pas
vérifiables depuis la doc**. C'est une question bloquante (§4).

---

## §2 — Ce que l'intégration impose à l'architecture PICHA

### 2.1 État actuel du Guichet (CONFIRMÉ)

- `enum PaymentProviders` = **`STRIPE`, `OFFLINE`** uniquement.
- `enum BoxOfficePaymentMethod` = **`CASH`, `CARD`, `FREE`** — **libellé purement déclaratif**. `CARD`
  aujourd'hui = « encaissé sur un TPE externe non intégré », l'opérateur saisit `amount_collected` à la main.
- `enum BoxOfficeSaleStatus` = **`PENDING`, `COMPLETED`** — pas d'état d'attente de paiement, pas d'échec.
- `CreateBoxOfficeSaleHandler` est **synchrone et atomique**, une seule transaction DB :
  insert `box_office_sales` `PENDING` → `lockForUpdateById(product_price_id)` → `validatePrice` /
  `validateScannable` / `validateStock` → `CreateAttendeeHandler` (crée **un Order `COMPLETED`**,
  `payment_status = PAYMENT_RECEIVED`/`NO_PAYMENT_REQUIRED`, `is_manually_created = true`, **1 Order par
  billet**, décrément du stock dans `fireEventsAndUpdateQuantities`) → vente `COMPLETED` → retour.
- **D2 (ratifiée)** : « encaissement **avant** création de la vente ». L'opérateur encaisse
  physiquement, *puis* valide, et tout est créé `COMPLETED` d'un coup.
- Webhook entrant : **un seul existe**, `POST /public/webhooks/stripe` (`StripeIncomingWebhookAction`).
  Route **hors `auth:api`**, sécurité = signature Stripe, `dispatch()` en file, réponse `204` rapide,
  idempotence via cache `stripe_event_<id>` (60 min).

### 2.2 Extension de `PaymentProviders`

| Piste | Conséquence |
|---|---|
| Ajouter `SUMUP` (ou `CARD_TERMINAL`) à l'enum | L'enum est balayée par des `match`/`switch` dans `MarkOrderAsPaidService`, `PaymentIntentSucceededHandler`, calcul de frais (`OrderApplicationFeeService`), facturation, `OrderResource`, chemins de remboursement, stats. Chaque point non exhaustif casse. **D3 option B du doc initial l'avait déjà signalé comme « risqué ».** |
| Ne **pas** toucher l'enum : `orders.payment_provider` reste `OFFLINE`, le détail « TPE SumUp » vit **uniquement sur `box_office_sales`** (nouvelle colonne `payment_provider` + `external_transaction_id`) | Surface de code partagée intacte. Cohérent avec la logique « `box_office_sales` = table de projection Guichet ». Le rapprochement comptable se fait sur `box_office_sales`, pas sur `orders`. |

→ **DÉCISION REQUISE (DS4).** Recommandation d'instinct : **ne pas étendre l'enum globale** au MVP.

### 2.3 Endpoint de réception du webhook

Nouvel `POST /public/webhooks/sumup/{token}` (`SumUpIncomingWebhookAction`), calqué sur le pattern
Stripe **sauf la vérification** (voir §1.3) :

- hors `auth:api`, dans le groupe `/public` ;
- `dispatch()` vers un handler en file, réponse **`2xx` immédiate** (SumUp attend un 2xx, sinon l'envoi est considéré en erreur) ;
- idempotence : cache / colonne sur `(client_transaction_id, status)` — un même événement peut arriver plusieurs fois ;
- le handler : (1) résout la vente via le `token`, (2) **appelle l'API SumUp** pour confirmer statut + montant,
  (3) si `successful` + montant OK → exécute la logique de création du billet, (4) si `failed` → vente
  `FAILED` + libération du stock réservé.

### 2.4 Le vrai sujet : changement de séquence par rapport à D2

Un paiement TPE **asynchrone** ne rentre pas dans `CreateBoxOfficeSaleHandler` tel quel. La séquence
devient :

| Étape | Aujourd'hui (D2, `CASH`/`CARD` déclaratif) | Avec TPE SumUp intégré |
|---|---|---|
| 1 | Opérateur encaisse **physiquement**, puis valide | Opérateur construit la vente et clique « Encaisser sur TPE » |
| 2 | 1 transaction DB : vente + Order + Attendee + stock, tout `COMPLETED` | Serveur : `validatePrice`/`validateScannable`/`validateStock`, crée `box_office_sales` en **`AWAITING_PAYMENT`**, **réserve le stock**, appelle SumUp `create_checkout`, stocke `client_transaction_id`. **Aucun Order ni Attendee créé.** |
| 3 | — | Le Solo demande la carte. UI opérateur en attente (polling `GET /box-office-sales/{id}` ou SSE). |
| 4 | — | Webhook SumUp → confirmation out-of-band → **alors seulement** : `CreateAttendeeHandler` (Order + Attendee + décrément stock définitif) → vente `COMPLETED`. |
| 5 | — | Échec / timeout / annulation opérateur → `terminate` → vente `FAILED`/`CANCELLED` → **libération du stock réservé**. |

Ce que ça impose en plus du code webhook :

| Besoin | Pourquoi il n'existe pas aujourd'hui | Impact |
|---|---|---|
| **États `AWAITING_PAYMENT`, `FAILED`, `CANCELLED`** sur `BoxOfficeSaleStatus` + machine à états | l'enum n'a que `PENDING`/`COMPLETED` | **[Schéma]** + **[Code]** moyen |
| **Réservation de stock pour une vente en attente** | le stock n'est décrémenté qu'à la création de l'`OrderItem` dans `CreateAttendeeHandler`. Une vente TPE en attente ne « tient » aucun stock → deux opérateurs peuvent lancer un paiement sur le dernier billet, un des deux paiements aboutit sans billet à émettre | **[Code]** élevé. Le flux web natif résout ça avec `reserved_until` + `ProductQuantityUpdateService` ; le Guichet n'a **rien** de cela. Options : compteur de réservation temporaire, ou assumer la course et **rembourser** l'excédent (comme `PaymentIntentSucceededHandler::handleExpiredOrder` le fait pour le web). |
| **Colonne `external_transaction_id` / `reader_id`** sur `box_office_sales` | pas de référence externe stockée aujourd'hui | **[Schéma]** 1 migration |
| **Job de réconciliation** (vente `AWAITING_PAYMENT` depuis > N min → `GET` transaction SumUp → clôturer) | pas d'équivalent (le web a `StripeRefundExpiredOrderService`) | **[Code]** moyen. Indispensable : si le webhook n'arrive jamais (Solo hors ligne, panne réseau), la vente reste bloquée. |
| **Idempotence à deux niveaux** | aujourd'hui `idempotency_key` client couvre toute l'opération synchrone | `idempotency_key` garde « démarrer le checkout » ; la **finalisation** est pilotée par le webhook sur `client_transaction_id` ; les rejeux des deux doivent être sûrs. **[Code]** moyen |
| **Remboursement TPE** | `RefundOrderHandler` est **Stripe-only** ; l'API refund SumUp est distincte | **[Code]** — ou hors périmètre (remboursement sur le dashboard SumUp à la main) |
| **Liaison Solo ↔ poste/événement** | aucune notion de terminal en base | **[Schéma]** + **[UX]** config. Recoupe D29 (réglages par poste) et D23 (auth opérateur) du Kiosk v2 |
| **Binding front (attente/annulation)** | l'UI Guichet actuelle est synchrone (une requête → un billet) | **[Code]** front : écran d'attente, bouton annuler, polling/SSE, reprise après refresh |

### 2.5 Dépendance à la disponibilité SumUp

D17 (« Guichet online-only ») devient plus dure : le chemin TPE dépend de **trois** disponibilités
simultanées — réseau du poste, connectivité propre du Solo, et **uptime de l'API SumUp** + acheminement
du webhook jusqu'à `api.picha.fr`. Le mode `CASH` reste le secours ; `CARD` déclaratif aussi si on le garde.

---

## §3 — Comparaison avec `AWAITING_OFFLINE_PAYMENT` / `MarkOrderAsPaidService` (natif)

**Le mécanisme natif est la bonne analogie et un donneur de code partiel — mais pas réutilisable tel quel.**

| Aspect | Natif `AWAITING_OFFLINE_PAYMENT` | Besoin TPE SumUp | Réutilisable ? |
|---|---|---|---|
| **Idée générale** | Order créé dans un état « pas encore payé », un signal ultérieur le passe `COMPLETED` | idem — le webhook SumUp joue le rôle du clic « Marquer comme payé » | ✅ le **patron** |
| **Création de l'état d'attente** | `TransitionOrderToOfflinePaymentHandler` — **exige une session checkout** (`$order->getSessionId()` + `verifySession`), c'est un handler du flux **public**. Aucun handler **authentifié** ne crée un Order `AWAITING_OFFLINE_PAYMENT` (déjà noté D2-B du doc initial). | un handler Guichet **authentifié** qui crée la vente en attente + lance le checkout SumUp | ❌ à écrire |
| **Modèle de stock** | basé sur la réservation web (`reserved_until`, `isReservedOrderExpired`, `ProductQuantityUpdateService::updateQuantitiesFromOrder`) | le Guichet va direct en `COMPLETED` via `CreateAttendeeHandler`, pas de réservation | ❌ modèle absent côté Guichet |
| **Passage à `COMPLETED`** | `MarkOrderAsPaidService` : statut → `COMPLETED`, `payment_status` → `PAYMENT_RECEIVED`, attendees `AWAITING_PAYMENT` → `ACTIVE`, facture → `PAID`, `OrderStatusChangedEvent`, frais applicatifs en `OFFLINE`, **email de récap client** | même « queue » de finalisation | ⚠️ en partie — la logique de bascule est proche, mais les attendees Guichet ne passent pas par un état `AWAITING_PAYMENT` aujourd'hui, et les frais/`payment_provider` seraient à qualifier `SUMUP`/`OFFLINE` |
| **Déclencheur** | **action utilisateur authentifiée** (`MarkOrderAsPaidAction` → `isActionAuthorized`) | **webhook non authentifié** → impose la confirmation out-of-band (§1.3) | ❌ modèle de confiance différent |
| **Pré-condition métier** | `event_settings` doit avoir `OFFLINE` activé | sans objet au Guichet | ❌ |
| **Réf. transaction externe** | aucune | `client_transaction_id` SumUp indispensable | ❌ à ajouter |
| **Acteur** | 1 humain, confirme « plus tard », pas de rapprochement de montant | système externe, **rapprochement montant obligatoire** | ❌ |

**Synthèse §3 :** on peut s'inspirer de la « queue de finalisation » de `MarkOrderAsPaidService` et du
pattern webhook de `IncomingWebhookHandler` (file + idempotence). Tout le reste — création de la vente
en attente authentifiée, réservation de stock, orchestration terminal (checkout / terminate /
réconciliation), confirmation out-of-band — **n'a aucun équivalent natif** et est du code neuf.

---

## §4 — Questions bloquantes hors dépôt (NON DÉTERMINÉ)

Aucune ne se répond par lecture du code. Tant qu'elles ne sont pas tranchées, l'option B (intégration)
**n'est pas spécifiable**.

| # | Question | Pourquoi c'est bloquant |
|---|---|---|
| Q1 | **PICHA a-t-il un compte marchand SumUp ?** À quel nom, rattaché à quelle entité / quel pays ? | Le pays du compte verrouille le lecteur au premier paiement. Détermine la zone (Q3). |
| Q2 | **Ce compte a-t-il l'accès API développeur activé** (clé API / client OAuth, `affiliate key` + `app_id`) ? | La Cloud API n'est pas active par défaut sur tous les comptes. Sans elle, zéro automatisation possible. |
| Q3 | **Zone d'exploitation : Mayotte (976), Réunion (974), France métropole, autre ?** | SumUp verrouille le lecteur au pays du compte ; la dispo du Solo et de la Cloud API est *region-specific*. |
| Q4 | **Le lecteur SumUp *Solo* + la Cloud API sont-ils disponibles dans cette zone ?** (vérifier le webshop SumUp du pays + confirmer l'activation Cloud API auprès du support SumUp) | La doc dit explicitement de vérifier avant tout rollout. Mayotte = DOM euro/UE mais dispo commerciale non garantie. |
| Q5 | **Quel matériel PICHA possède / peut acquérir ?** Des *Solo* (terminaux autonomes) ou seulement des lecteurs *Air* / l'app mobile ? Combien, un par file de guichet ? | Seul le *Solo* fonctionne avec la Cloud API. Un parc *Air* rend l'intégration impossible. |
| Q6 | **Devise** : toute la tarification Guichet est-elle en EUR ? | Le Solo transige dans la devise du compte marchand. |
| Q7 | **Frais & règlement SumUp** : taux par transaction, délai de payout — acceptables vs gestion actuelle ? | Impacte le rapprochement « ventes vs encaissé » (D16 / écran box-office-stats) et la décision d'y aller. |
| Q8 | **Remboursements** : PICHA a-t-il besoin de rembourser un paiement TPE *depuis l'app*, ou le dashboard SumUp suffit ? | Détermine si le chantier « refund SumUp » est dans le périmètre. |
| Q9 | **Réseau sur site** : le Solo a-t-il sa connectivité (SIM/WiFi) ? `api.picha.fr` expose-t-il un endpoint HTTPS public joignable par les serveurs SumUp pour le webhook ? | Le flux exige les deux en même temps. |
| Q10 | **Pourboire** : désactiver `tip_rates`/`tip_timeout` sur le checkout (a priori oui pour de la billetterie) ? | Paramètre du `create_checkout`. |
| Q11 | **Périmètre multi-tenant** : le compte SumUp est-il **global PICHA** (une intégration centrale) ou par organisateur (comme Stripe Connect aujourd'hui) ? | Change entièrement le modèle de stockage des credentials et l'UI de configuration. |
| Q12 | **SumUp publie-t-il des plages d'IP sortantes** pour ses webhooks ? | Conditionne la possibilité d'une allowlist IP (§1.3). |

---

## §5 — Décisions à trancher avec Jo

Numérotées `DS` (Décision SumUp) pour ne pas heurter la numérotation `D…` existante. **Prérequis
absolu : Q1–Q5 répondues favorablement.** Si l'une est « non », s'arrêter à **DS1 = A**.

| # | Décision | Options | Recommandation d'instinct |
|---|---|---|---|
| **DS1** | **Y aller ou pas, et quand** | **A** — statu quo : `CARD` reste déclaratif (l'opérateur tape le TPE physiquement puis valide), zéro dev. · **B** — intégration Cloud API SumUp, chantier dédié **après** le Kiosk v2. · **C** — intégration en parallèle du Kiosk v2. | **A pour la v2.1**, **B** comme chantier dédié une fois Q1–Q5 vertes. Jamais **C** (deux gros chantiers async en même temps). |
| **DS2** | **Prémisse « vérification de signature »** | **A** — acter qu'il n'y a **pas** de signature SumUp ; sécurité = `return_url` à token unique **+ confirmation out-of-band systématique** (rappel API SumUp) **+ rate-limit/allowlist**. · **B** — exiger de SumUp une confirmation écrite qu'aucun HMAC n'est disponible avant de valider l'architecture. | **A** (avec **B** en due diligence). Le webhook n'est qu'un réveil ; la source de vérité = l'API SumUp authentifiée. |
| **DS3** | **Moment de création du billet** (revient sur D2 pour le seul chemin TPE) | **A** — vente `AWAITING_PAYMENT` créée **avant** confirmation, billet émis **par le webhook** (+ job de réconciliation). · **B** — l'opérateur attend l'écran « paiement accepté » sur le Solo puis clique « Valider » → on retombe dans le flux synchrone actuel (le webhook ne sert que de contrôle a posteriori). | **A** si on veut le vrai « feel » Weezevent et le rapprochement garanti. **B** est un moindre mal (moins de code, pas de machine à états) mais ré-introduit l'erreur de saisie et la double validation. |
| **DS4** | **Extension de `PaymentProviders`** | **A** — ne pas toucher l'enum ; `orders.payment_provider = OFFLINE`, le détail TPE vit sur `box_office_sales` (`payment_provider`, `external_transaction_id`). · **B** — ajouter `SUMUP` à l'enum globale. | **A** au MVP (surface de code partagée intacte). **B** seulement si un besoin comptable transverse l'exige. |
| **DS5** | **Réservation de stock d'une vente en attente** | **A** — compteur de réservation temporaire (vraie réservation, TTL, libéré sur échec/timeout). · **B** — pas de réservation : on assume la course sur le dernier billet et on **rembourse** le paiement orphelin (repli sur le pattern `handleExpiredOrder` de Stripe). · **C** — verrou applicatif « un seul checkout TPE en cours par `product_price_id` ». | **C** en première intention (simple, borne le risque à 1 transaction), **A** si le débit guichet le justifie. **B** implique DS8 = intégration refund. |
| **DS6** | **États `box_office_sales`** | **A** — ajouter `AWAITING_PAYMENT`, `FAILED`, `CANCELLED` + machine à états explicite. · **B** — réutiliser `PENDING` comme « en attente TPE » et ne rien ajouter. | **A** (`PENDING` a déjà un sens « ligne d'idempotence réservée » dans le handler actuel — le surcharger serait ambigu). |
| **DS7** | **Job de réconciliation** | **A** — job planifié : toute vente `AWAITING_PAYMENT` de plus de N min → `GET` transaction SumUp → clôturer (`COMPLETED` si payé, `FAILED` sinon) + `terminate` défensif. · **B** — pas de job, l'opérateur relance / annule à la main. | **A** — obligatoire pour un flux async fiable ; **B** laisse des ventes fantômes. Fixer N (ex. 3 min). |
| **DS8** | **Remboursement TPE dans l'app** | **A** — hors périmètre : remboursement sur le dashboard SumUp, l'app ne fait que tracer. · **B** — intégrer l'API refund SumUp (nouveau handler, `RefundOrderHandler` est Stripe-only). | **A** au MVP, sauf si DS5 = B (alors **B** devient nécessaire pour les paiements orphelins). |
| **DS9** | **Liaison Solo ↔ poste** | **A** — `reader_id` configuré au niveau **événement** (1 Solo par événement). · **B** — `reader_id` par **poste/opérateur** (recoupe D29 / D23 Kiosk v2). · **C** — l'opérateur choisit le Solo dans une liste (`GET readers`) à l'ouverture de session. | Dépend de D23/D29. **C** si plusieurs files ; **A** si un seul guichet. |
| **DS10** | **Périmètre du compte SumUp** | **A** — compte **global PICHA**, credentials en config serveur (comme un secret d'app). · **B** — par organisateur, credentials en base chiffrés (modèle Stripe Connect). | **A** tant que PICHA est le seul exploitant du Guichet. **B** = gros chantier, seulement si des tiers vendent au guichet. |
| **DS11** | **Pourboire** | **A** — désactivé (`tip_rates`/`tip_timeout` non envoyés). · **B** — activé. | **A** (billetterie). |
| **DS12** | **Fallback** | **A** — si SumUp/Solo/réseau indisponible : l'opérateur bascule sur `CASH` ou `CARD` déclaratif, l'intégration TPE est optionnelle par vente. · **B** — pas de fallback, le guichet est bloqué. | **A** — garder `CASH` et `CARD` déclaratif à côté du TPE intégré. |

### Récapitulatif — recommandations de séquencement

| # | Recommandation d'instinct | Nature |
|---|---|---|
| DS1 | **A** (statu quo) pour la v2.1 ; **B** = chantier dédié après Q1–Q5 vertes | Métier / planning |
| DS2 | **A** — pas de signature SumUp ; token unique + confirmation out-of-band + rate-limit | Sécu |
| DS3 | **A** (billet émis par le webhook) si vrai flux async ; **B** en repli | Métier / Code |
| DS4 | **A** — ne pas étendre `PaymentProviders`, tout sur `box_office_sales` | Schéma |
| DS5 | **C** (verrou 1 checkout / palier), **A** si débit élevé | Code |
| DS6 | **A** — nouveaux états + machine à états | Schéma / Code |
| DS7 | **A** — job de réconciliation, N ≈ 3 min | Code |
| DS8 | **A** — refund hors app (sauf si DS5 = B) | Périmètre |
| DS9 | **C** ou **A** selon D23/D29 | UX / Schéma |
| DS10 | **A** — compte SumUp global PICHA | Infra / Sécu |
| DS11 | **A** — pourboire désactivé | UX |
| DS12 | **A** — `CASH` / `CARD` déclaratif conservés en fallback | UX |

---

## Annexe — chiffrage indicatif si DS1 = B (à titre d'ordre de grandeur, non un plan)

- Migration `box_office_sales` : `payment_provider`, `external_transaction_id`, `reader_id`, `return_url_token`, nouveaux statuts.
- 1 handler « initier vente TPE » (authentifié) + client HTTP SumUp (ou SDK `sumup/sumup-ecom-php-sdk`) + config credentials.
- 1 action webhook `/public/webhooks/sumup/{token}` + 1 handler de finalisation (file, idempotence, confirmation out-of-band).
- 1 machine à états `BoxOfficeSaleStatus` + libération de stock.
- 1 job de réconciliation planifié + `terminate` défensif.
- Front : écran d'attente paiement, annulation, polling/SSE, reprise après refresh.
- Tests unitaires : machine à états, idempotence webhook, rapprochement montant, course sur le dernier billet, timeout.
- **Hors périmètre** sauf décision : refund TPE in-app, multi-tenant credentials, tips.
