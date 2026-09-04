# PICHA Box Office — Audit (v2, complété et corrigé)

Date : 3 septembre 2026 · Remplace `PICHA_BOX_OFFICE_AUDIT.md` (v1, conservé intact).
Méthode : lecture de code et de schéma dans `/opt/digit-ticket`. Aucune exécution (pas de PHP/artisan/conteneur dans l'environnement d'analyse). Aucune modification du dépôt.
Documents frères : `PICHA_BASELINE_INVENTORY.md`, `PICHA_BOX_OFFICE_SECURITY_FINDINGS.md`, `PICHA_BOX_OFFICE_CHECKIN_MATRIX.md`, `PICHA_BOX_OFFICE_DECISIONS_REQUIRED.md`, `PICHA_BOX_OFFICE_DESIGN_OPTIONS.md`, `PICHA_BOX_OFFICE_FIRST_SLICE.md`.

Catégories : **CONFIRMÉ** · **NUANCÉ** (v1 approximatif) · **RÉFUTÉ** · **NON DÉTERMINÉ** · **NOUVEAU**

---

## A. Vérification des 12 constats critiques de la mission

| # | Constat | Verdict | Preuve / correction |
|---|---|---|---|
| 1 | Dépôt `/opt/digit-ticket` sur `staging` | **CONFIRMÉ** | `git branch --show-current` = `staging` |
| 2 | Working tree sale, code Somaroho non commité | **CONFIRMÉ + PRÉCISÉ** | 35 fichiers suivis modifiés, ~90 non suivis. v1 disait « 35 modifiés, 66 non suivis, 13 vides » — en réalité **34 M + 1 D**, **~20 fragments de collage** (pas 13), **~30 `.bak`**, **2 dumps SQL de 24 Mo dans `backups/`** non mentionnés par v1, **arbre `themes/festival/` entier** non mentionné. Détail : `PICHA_BASELINE_INVENTORY.md`. |
| 3 | Fork Hi.Events `1.10.0-beta` | **CONFIRMÉ** | `VERSION`, `composer.json` (`"laravel/framework":"^12.0"`), tag `v.1.10.0-beta` → `619cbf0a` ; 9 commits DIGIT au-dessus |
| 4 | Flux manuel `CreateAttendeeHandler` crée Order + Attendee | **CONFIRMÉ** | `CreateAttendeeAction.php` → `CreateAttendeeHandler.php:62-111`. Détail du flux en §C. |
| 5 | `amount_paid` client sans confrontation au prix serveur | **CONFIRMÉ** | `CreateAttendeeHandler.php:116, 209-213` — aucune lecture de `product_prices.price`. Voir `SECURITY_FINDINGS S1`. |
| 6 | Vérif stock sans verrou → 2 ventes concurrentes | **CONFIRMÉ par lecture** (test non exécuté) | `ProductRepository::getQuantityRemainingForProductPrice` (SELECT nu) + `ProductQuantityUpdateService.php:28-43` (`quantity_sold + 1`, transaction imbriquée, pas de verrou). Voir `SECURITY_FINDINGS S2`. |
| 7 | Paiement offline public non réutilisable au guichet | **CONFIRMÉ** | `TransitionOrderToOfflinePaymentHandler.php:50-55` exige `order.session_id` + `verifySession()` **et** `:104` `isOrderReserved()`. Aucun flux authentifié ne place un Order en `AWAITING_OFFLINE_PAYMENT`. |
| 8 | QR fondé sur `attendee.public_id` | **CONFIRMÉ** | Natif : rendu frontend à partir de `public_id`. Somaroho : `AttendeeTicketMail.php` + `attendee-ticket-pdf.blade.php` encodent `$attendee->getPublicId()`. |
| 9 | Génération PDF ajoutée dans le working tree | **CONFIRMÉ + PRÉCISÉ** | `AttendeeTicketMail::generateTicketPdf()` (working tree) + vue `attendee-ticket-pdf.blade.php` (**non suivie**). **Correction v1** : `barryvdh/laravel-dompdf ^3` + `dompdf/dompdf` **sont déjà dans `composer.json`/`composer.lock` de base** — seul `simplesoftwareio/simple-qrcode` est ajouté (et non commité). L'extension PHP `gd` est ajoutée aux deux Dockerfiles. |
| 10 | Check-in conditionné par statut attendee, liste, produit, fenêtre | **CONFIRMÉ** | Matrice complète : `PICHA_BOX_OFFICE_CHECKIN_MATRIX.md`. |
| 11 | Contrainte unique DIGIT contre le double check-in concurrent | **CONFIRMÉ + NUANCÉ** | `modules/digit/database/migrations/2026_07_18_000001…` : index **partiel unique** `(attendee_id, check_in_list_id) WHERE deleted_at IS NULL`. **Nuance** : la violation concurrente n'est **pas catchée** → HTTP 500 au lieu d'un 409 métier (`SECURITY_FINDINGS S5`). L'intégrité est protégée, pas l'UX. |
| 12 | Absence d'idempotence et de tests sur les briques critiques | **CONFIRMÉ** | `grep idempoten app/` = 0 (le seul mécanisme est dans `modules/digit`). Aucun test sur `CreateAttendeeHandler` ni `MarkOrderAsPaidService` (`find tests -iname '*CreateAttendee*'` = 0). |

---

## B. Corrections apportées à l'audit v1

| §v1 | Affirmation v1 | Correction v2 |
|---|---|---|
| §1 | « HEAD 2aa3ee8 — 2026-07-18 » | Exact. `git describe` = `DIGIT-STAGING-BRACELETS-v1.0-1-g2aa3ee8d`. Il existe des tags `digit-staging-baseline-v1.0…v1.3` (pointant sur des commits **antérieurs** à HEAD) et `DIGIT-STAGING-BRACELETS-v1.0` (= `c08499bb`, l'avant-dernier commit). **Aucun tag ne pointe sur HEAD.** |
| §1 | « 13 fichiers vides à la racine » | **~20** entrées parasites (fragments de collage d'un middleware PHP), tailles 0 / 3629 / 13067 o. Certaines contiennent des caractères shell → `git clean -n` avant toute suppression. |
| §1 | ne mentionne pas `backups/` | **`backups/` (~50 Mo, 2 dumps SQL de staging + 9 snapshots) n'est pas dans `.gitignore`.** Risque élevé. |
| §1 | ne mentionne pas les `.env.staging.bak` | `frontend/.env.staging.bak-*` (2) **non couverts par `.gitignore`**. Contenu = variables `VITE_*` seulement (clé Stripe *publishable*, URLs). **Aucune clé secrète `sk_`/`whsec_` détectée.** |
| §3 | « Rôles : SUPERADMIN, ADMIN, ORGANIZER uniquement » | Confirmé. `BaseAction::isActionAuthorized(..., Role $minimumRole = Role::ORGANIZER)` (`BaseAction.php:160-163`) — **défaut = ORGANIZER**, confirmé. `CreateAttendeeAction` n'override pas → ORGANIZER minimum. |
| §4.3 | « Il n'existe aucune signature du QR : … c'est le modèle Hi.Events » | Exact, mais **à nuancer** : le `public_id` est CSPRNG (`Str::random`), non séquentiel (~34–36 bits), et le check-in exige aussi le `check_in_list.short_id` (~74 bits). Le QR n'est pas *forgeable* ; il est *rejouable/transférable* comme tout billet. `SECURITY_FINDINGS S7`. |
| §4.5 | « aucun verrou … deux créations réussissent » | Confirmé par lecture. **Test non exécuté** (v1 le présentait comme acquis ; v2 le classe CONFIRMÉ-par-lecture, test spécifié dans `SECURITY_FINDINGS S2`). |
| §4.6 | « Colonnes disponibles : first_name, last_name, email, public_id, short_id, **notes** » | **RÉFUTÉ pour `notes` et `short_id`.** La table `attendees` **n'a pas de colonne `notes`**. La recherche plein-texte (`AttendeeRepository::findByEventId:64-76`) porte sur : `(first_name||' '||last_name)`, `last_name`, `first_name`, `public_id`, `email` — via **`ILIKE '%q%'`** (partiel, insensible à la casse, **sensible aux accents**). `filter_fields` autorisés = **`status`, `product_id`, `product_price_id` uniquement** (`AttendeeDomainObject::getAllowedFilterFields`). Détail §D. |
| §8 | « Existence de `barryvdh/laravel-dompdf` … à vérifier » | **Résolu : présent, `^3.0`, dans le `composer.json` de base.** |
| §8 | « Comportement de `OrderStatusChangedEvent` avec `sendEmails: false` » | **Résolu**, §E. |
| §8 | « mécanisme de réponses aux questions utilisable hors checkout » | **Résolu : non.** §F. |
| §10 | « Traçabilité du moyen de paiement : aucun champ sur l'Order » | **NUANCÉ.** `orders.payment_provider` **existe** (migration `2025_01_03_013621…`), enum `PaymentProviders` = `STRIPE | OFFLINE` **seulement**. Le flux manuel ne le renseigne pas. Insuffisant pour espèces/TPE/gratuit → extension nécessaire (D3). |

---

## C. Flux `CreateAttendeeHandler` — confirmé, ligne à ligne

`CreateAttendeeHandler::handle` (`:62-111`), **une transaction** (`DatabaseManager::transaction`) :

1. `calculateTaxesAndFees` — valide l'existence des `tax_or_fee_id`, **conserve le `amount` client** (`SECURITY_FINDINGS S6`).
2. `createOrder` (`:113-136`) : `status = COMPLETED`, `payment_status = NO_PAYMENT_REQUIRED` si `amount_paid == 0` sinon `PAYMENT_RECEIVED`, `is_manually_created = true`, `currency = $event->getCurrency()`, `short_id`/`public_id` via `IdHelper`, `total_gross = amount_paid`. **`payment_provider` non renseigné.**
3. Chargement produit : `product_type = TICKET`, `event_id` concordant, sinon `NoTicketsAvailableException('This ticket is invalid')`.
4. `getProductPriceId` (`:141-160`) : si `product_price_id` fourni → vérifie l'appartenance au produit (`InvalidProductPriceId` sinon) ; sinon → 1er prix du produit.
5. `getQuantityRemainingForProductPrice` ; si `<= 0` → `NoTicketsAvailableException`. **(2e appel identique à `:95` — redondant.)**
6. `createOrderItem` (`:203-220`) : `quantity = 1`, `price = total_before_additions = amount_paid`, `total_gross = amount_paid + rollup`, `product_price_id = <résolu>`.
7. `createAttendee` (`:222-237`) : `status = ACTIVE`, `public_id`/`short_id` via `IdHelper`, `product_price_id = $attendeeDTO->product_price_id` (**valeur brute** — `SECURITY_FINDINGS S8`).
8. `updateOrderTotals`.
9. `fireEventsAndUpdateQuantities` (`:239-249`) : `increaseQuantitySold(priceId: $attendeeDTO->product_price_id)` (**brute**) + `event(OrderStatusChangedEvent(order, sendEmails: $send_confirmation_email))` (`createInvoice` = défaut `false`).
10. `queueWebhooks` : `DomainEventDispatcherService->dispatch(OrderEvent(ORDER_CREATED, orderId))`.

**Contrat d'entrée** (`CreateAttendeeRequest`) : `product_id` (int, requis), `product_price_id` (int, `nullable`+`required` → présent non-null), `email` (**requis, format email**), `first_name` (requis, max 40), `last_name` (**optionnel**, max 40 — le frontend le rend requis), `amount_paid` (requis, `gte:0`, `decimal:0,2`), `send_confirmation_email` (requis, bool), `taxes_and_fees[]` (optionnel), `locale` (requis, dans `Locale::getSupportedLocales()`).

**Limites confirmées** : 1 appel = 1 billet ; `amount_paid` non confronté au prix ; aucune question de checkout ; `payment_provider` non renseigné ; Order naît `COMPLETED` (schéma « billet remis puis payé » **impossible** avec ce handler) ; pas d'idempotence ; pas de verrou stock.

---

## D. Recherche d'attendees — déterminé

`GET /events/{event_id}/attendees` → `AttendeeRepository::findByEventId(int $eventId, QueryParamsDTO $params)` (`:57-108`).

| Aspect | Constat | Preuve |
|---|---|---|
| Champs `query` (plein-texte) | `(first_name||' '||last_name)`, `last_name`, `first_name`, `public_id`, `email` | `:64-76` |
| Opérateur | `ILIKE '%' . $query . '%'` | `:71` |
| Correspondance | partielle, **insensible à la casse**, **sensible aux accents** (pas d'`unaccent`/`citext`) | `ILIKE` Postgres |
| `filter_fields` autorisés | `status`, `product_id`, `product_price_id` | `AttendeeDomainObject::getAllowedFilterFields` |
| Tri | `validateSortColumn` / `validateSortDirection` (liste blanche) ; clé spéciale `TICKET_NAME_SORT_KEY` → jointure `products` | `:88-101` |
| Filtrage statut order | seulement `COMPLETED`, `CANCELLED`, `AWAITING_OFFLINE_PAYMENT` | `:79-81` |
| Cloisonnement | `attendees.event_id = $eventId` + `isActionAuthorized($eventId, EventDomainObject::class)` (compte + rôle) | `GetAttendeesAction` (via BaseAction) |
| Pagination | `LengthAwarePaginator` (`paginateWhere`) — fait un `COUNT` | `:103-107` |
| Index | `idx_attendees_first_name_trgm`, `_last_name_trgm`, `_email_trgm`, `_public_id_trgm` (GIN `gin_trgm_ops`) + `idx_attendees_public_id_lower` | `schema.sql:627-640` |
| Performance `%q%` | **supportée par les index GIN trigram** (contrairement à un btree classique) — pas de seq scan sur ces colonnes | extension `pg_trgm` |
| Recherche par téléphone | **impossible directement** — pas de colonne ; le téléphone n'existe que comme `question_answers.answer` (jsonb), non joint par cette requête | §F |
| Recherche de doublon email | oui, `email ILIKE '%q%'` + `idx_orders_email_trgm` / `idx_attendees_email_trgm` | — |

---

## E. Effets de bord de `OrderStatusChangedEvent` — déterminé

Listeners (auto-découverts, Laravel 12), tous dans `app/Listeners/` :

| Listener | Condition d'action | Effet | Depuis `CreateAttendeeHandler` (`sendEmails = $x`, `createInvoice = false`) |
|---|---|---|---|
| `SendOrderDetailsEmailListener` | `sendEmails === true` | `dispatch(SendOrderDetailsEmailJob)` — mail récap + billet (avec **PDF** si working tree Somaroho) | `x = true` → mail envoyé ; `x = false` → **aucun mail** |
| `UpdateEventStatsListener` | `order->isOrderCompleted()` | `dispatch(UpdateEventStatisticsJob)` | Order `COMPLETED` → **toujours déclenché** (indépendant de `sendEmails`) |
| `CreateInvoiceListener` | `createInvoice === true` **et** status ∈ {`AWAITING_OFFLINE_PAYMENT`,`COMPLETED`} | `InvoiceCreateService::createInvoiceForOrder` | `createInvoice = false` → **jamais de facture** par le flux manuel |
| `ResolveWaitlistEntryOnOrderCompletedListener` | status `COMPLETED` | résout les `waitlist_entries` liées à l'`order_id` | Aucune waitlist liée à une création manuelle → **no-op** |

Plus, hors listeners : `DomainEventDispatcherService->dispatch(OrderEvent(ORDER_CREATED))` → **webhooks `spatie/laravel-webhook-server`** + éventuels handlers de domaine.

**Réponse à la question v1** : avec `sendEmails = false`, les seuls effets sont : lignes DB (order/item/attendee), `quantity_sold`/`used_capacity` +1, `UpdateEventStatisticsJob`, webhook `ORDER_CREATED`. **Pas de mail, pas de PDF, pas de facture.**

---

## F. Questions / téléphone / organisation — déterminé

| Question | Réponse |
|---|---|
| Modèle | `question_answers (question_id, order_id NOT NULL, attendee_id NULL, ticket_id NULL, answer jsonb)` — `app/Models/QuestionAnswer.php`, `schema.sql` |
| `questions` | `(title, required bool, type varchar, options jsonb, belongs_to varchar NOT NULL, is_hidden bool)` |
| `QuestionTypeEnum` | `ADDRESS, PHONE, SINGLE_LINE_TEXT, MULTI_LINE_TEXT, CHECKBOX, RADIO, DROPDOWN, MULTI_SELECT_DROPDOWN, DATE` — **`PHONE` est déjà commité côté backend** ; le working tree ajoute seulement l'input frontend. |
| Création de réponses | **uniquement** dans `CompleteOrderHandler` (méthodes privées `createOrderQuestions` `:198`, per-product `:249`) — **flux checkout public**. |
| `EditQuestionAnswerService` | **UPDATE seulement** (par `question_answer_id` existant). Ne crée rien. |
| Service réutilisable hors checkout | **AUCUN.** Il n'existe aucun point d'entrée pour stocker une réponse de question depuis un contexte authentifié / manuel. |
| `CreateAttendeeHandler` et les questions | **les ignore totalement** — pas de validation des questions `required`, pas de stockage. |

**Conséquence** : stocker téléphone ou organisation lors d'une vente guichet suppose **du code nouveau** (un service de création de `question_answers`, ou une extension du DTO/handler). Aucune valeur par défaut fictive n'est proposée (décision métier D7).

---

## G. PDF — confirmé

| Élément | Constat |
|---|---|
| Moteur | `barryvdh/laravel-dompdf ^3.0` (base) + `dompdf/dompdf` (base). QR : `simplesoftwareio/simple-qrcode ^4.2` (**ajouté, non commité**) + `bacon/bacon-qr-code`. |
| Code natif | Aucun PDF de billet natif (seul `.ics` en pièce jointe). Factures : `InvoiceCreateService` (DomPDF). |
| Code Somaroho | `AttendeeTicketMail::generateTicketPdf()` (privée, working tree) → `Pdf::loadView('attendee-ticket-pdf', […])->output()`. Vue `backend/resources/views/attendee-ticket-pdf.blade.php` (**non suivie**). |
| Dépendances non commitées | `simple-qrcode` (composer.json/lock modifiés mais pas commités), extension `gd` (Dockerfiles modifiés), vue Blade (non suivie). |
| Polices | `DejaVu Sans` (embarquée dans DomPDF) → accents FR OK. Pas de police custom. |
| Données dans le PDF | titre événement, date/heure (`d/m/Y H:i`), organisateur, adresse, type de billet, nom+email attendee, **QR = `public_id`**, `public_id` en clair (« Ticket ID »), footer optionnel, logo CDN optionnel. |
| Extraction en service réutilisable | **Possible** : la logique est autonome (produit, design settings, QR, logo, `Pdf::loadView`). À extraire en `AttendeeTicketPdfService::render(AttendeeDomainObject): string` et faire pointer `AttendeeTicketMail` dessus → aucun changement de comportement mail. |

---

## H. Composants réutilisables (mis à jour)

| Composant | Chemin | Usage Kiosk |
|---|---|---|
| `CreateAttendeeHandler` | `app/Services/Application/Handlers/Attendee/` | création Order+Attendee ACTIVE (à appeler depuis l'orchestrateur, **avec `product_price_id` explicite**) |
| `ProductRepository::getQuantityRemainingForProductPrice` | `app/Repository/Eloquent/` | affichage stock (⚠️ pas de verrou) |
| `ProductQuantityUpdateService` | `app/Services/Domain/Product/` | `increaseQuantitySold` (⚠️ pas de verrou) |
| `CheckInListRepository` + relation `products` (`product_check_in_lists`) | — | vérifier « produit rattaché à une liste active » **avant** la vente (D7) |
| `AttendeeRepository::findByEventId` (`query`) | — | recherche de doublons (nom/email/public_id) |
| `MarkOrderAsPaidService` | `app/Services/Domain/Order/` | uniquement si un Order est en `AWAITING_OFFLINE_PAYMENT` (précondition stricte) |
| `AttendeeTicketMail::generateTicketPdf` (à extraire) | working tree | driver PDF réutilisable |
| `BaseAction::isActionAuthorized` | — | autorisation compte + rôle (ORGANIZER par défaut) |
| `ResourceConflictException`, `ValidationException::withMessages`, `CannotCheckInException` | — | erreurs 409/422 conformes |
| `QuestionTypeEnum::PHONE` (backend) | `app/DomainObjects/Enums/` | brique existante si le téléphone passe par une question |
| `IdHelper` | `app/Helper/` | ⚠️ pas de check de collision (`SECURITY_FINDINGS S4`) |
| Module `Bracelets` (HMAC, `Idempotency-Key`) | `modules/digit/src/Bracelets/` | **référence** d'un pattern idempotent + signé déjà présent dans le dépôt (hors périmètre mais instructif) |

---

## I. Capacités manquantes (mise à jour)

1. Validation serveur du prix (produit + palier = montant) — **CRITIQUE**.
2. Verrou de stock (`lockForUpdate` / UPDATE conditionnel) — **CRITIQUE**.
3. Idempotence des créations (clé métier + contrainte unique) — **CRITIQUE**.
4. Unicité DB de `attendees.public_id` — **ÉLEVÉ** (S4, = migration).
5. Traçabilité du moyen de paiement (espèces/TPE/gratuit) — enum `PaymentProviders` insuffisante.
6. Multi-billets par vente.
7. Enregistrement propre du téléphone / de l'organisation (aucun service).
8. Gestion 409 (au lieu de 500) du double check-in concurrent — S5.
9. Service PDF réutilisable hors email.
10. Toute notion d'impression / job d'impression / réimpression tracée.
11. Toute notion de session de caisse / rapport de fin de service.
12. Tests de caractérisation (aucun sur les briques critiques).

---

## J. Points restant NON DÉTERMINÉS

| Point | Raison | Levée possible |
|---|---|---|
| Comportement réel sous course (S2) et double-scan concurrent (S5) | pas d'exécution possible ici | lancer les tests spécifiés dans le conteneur dev |
| Isolation effective d'une base de test | `phpunit.xml` : `DB_DATABASE` commenté, pas de connexion sqlite ; le module digit a son `InMemorySqliteTestCase` (non suivi) | confirmer la config `.env.testing` du conteneur `docker/development` |
| Détail de `IsAuthorizedService` (impersonation, comptes multiples) | non lu en profondeur | lecture ciblée si besoin |
| Valeur de `VITE_I_HAVE_PURCHASED_A_LICENCE` (licence Hi.Events) | fichier d'env — non inspecté volontairement | question directe à Jo |
| Contenu exact des webhooks `ORDER_CREATED` (payload, destinataires configurés) | `spatie/laravel-webhook-server` — config runtime | inspection DB `webhooks` de staging |

---

## K. Conclusion de l'audit

**L'audit est complet sur les points démontrables par lecture.** Il reste **incomplet sur 2 points qui exigent une exécution** : la démonstration chiffrée de la survente (S2) et du 500 concurrent (S5). Ces tests sont **spécifiés** (`CHECKIN_MATRIX §6`, `SECURITY_FINDINGS S2/S5`) et doivent être lancés dans le conteneur dev avant d'écrire la moindre ligne du Kiosk.

**Risques critiques confirmés** : baseline non figée · prix non validé · survente · absence d'idempotence.
**Nouveaux risques** : `attendees.public_id` non unique (S4) · 500 sur double-scan concurrent (S5) · retrait mention AGPL (S9).
**Affirmations v1 corrigées** : DomPDF déjà présent · pas de colonne `notes` · `payment_provider` existe mais insuffisant · QR non « forgeable » (rejouable) · `backups/` et `.env.staging.bak` non ignorés.
