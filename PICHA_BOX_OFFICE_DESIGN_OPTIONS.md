# PICHA Box Office / Kiosk — Options de conception

Date : 3 septembre 2026 · **Aucun code écrit.** Comparaison fondée sur l'audit.
Prérequis commun à toutes les options : baseline figée (D1), décisions D2–D19 tranchées, tests de caractérisation écrits (`FIRST_SLICE`).

Conventions du dépôt à respecter (CLAUDE.md) : `Action → Handler → Service → Repository` ; pas d'Eloquent hors repository ; DTO Spatie `BaseDataObject` ; IDs entiers auto-incrémentés ; exceptions custom → `ValidationException`/`ResourceConflictException` ; `DatabaseTransactions` en test ; Mantine + SCSS modules ; Lingui ; `showSuccess`/`showError`.

---

## Option A — Adaptation sécurisée du flux manuel existant

### Idée
Un endpoint Kiosk réutilise **directement** `CreateAttendeeHandler`, précédé/suivi de garde-fous portés par un **handler mince** `CreateBoxOfficeSaleHandler` qui n'orchestre presque rien : il valide, il délègue, il trace.

### Composants réutilisés
- `CreateAttendeeHandler` (inchangé) — création Order+OrderItem+Attendee ACTIVE, QR natif, événements.
- `ProductRepository`, `ProductPriceRepository` — lecture prix + stock.
- `CheckInListRepository` + `product_check_in_lists` — vérif D11.
- `AttendeeRepository::findByEventId` — doublons D12.
- `BaseAction::isActionAuthorized` — ORGANIZER.
- `AttendeeTicketMail::generateTicketPdf` extrait en `AttendeeTicketPdfService`.

### Corrections nécessaires (dans le nouveau handler, PAS dans `CreateAttendeeHandler`)
| Sujet | Correction |
|---|---|
| Prix (S1/S6) | Avant délégation : charger `product_price.price`, recalculer taxes serveur, **rejeter** si `amount_paid` ≠ prix attendu (ou accepter un écart borné explicitement décidé). Passer à `CreateAttendeeHandler` un `amount_paid` = valeur serveur. |
| Stock (S2) | Nouveau repo method `ProductPriceRepository::lockForUpdateById($id)` appelée **dans la transaction englobante** avant `CreateAttendeeHandler`. |
| Idempotence (S9→D9) | Insérer `box_office_sales{idempotency_key UNIQUE, ...}` **en premier** ; `catch QueryException 23505` → renvoyer la vente existante. |
| Produit non scannable (D11) | `SELECT 1 FROM product_check_in_lists pcil JOIN check_in_lists cil … WHERE pcil.product_id = ? AND cil actif` → sinon `ResourceConflictException`. |
| Traçabilité paiement (D3) | colonnes de `box_office_sales`. |

### Frontière transactionnelle
```
BEGIN (CreateBoxOfficeSaleHandler)
  INSERT box_office_sales (idempotency_key, agent, payment_method, status=PENDING)   -- garde-fou idempotence
  SELECT ... FROM product_prices WHERE id = ? FOR UPDATE                              -- garde-fou stock
  validate price/quantity/checkin-list
  CreateAttendeeHandler::handle(...)          -- ouvre sa PROPRE transaction imbriquée (savepoint)
  UPDATE box_office_sales SET order_id=?, attendee_id=?, status=COMPLETED
COMMIT
-- hors transaction :
dispatch(GenerateAndPrintTicketJob) ou renvoyer le PDF au front pour impression
```
⚠️ `CreateAttendeeHandler::handle` ouvre `DatabaseManager::transaction` → imbriqué = **savepoint**. Un échec interne rollback le savepoint mais **pas** l'INSERT `box_office_sales` initial → prévoir un `catch` qui marque la vente `FAILED` (ou rollback global).

### Diagramme de séquence
```
Agent(front)      KioskAction        CreateBoxOfficeSaleHandler     CreateAttendeeHandler     DB / Print
   |  POST /events/{id}/box-office-sales {items[], payment_method, idempotency_key}
   |----------------->|
   |                  | isActionAuthorized(ORGANIZER)
   |                  |----------------------------->|
   |                  |                              | BEGIN
   |                  |                              | INSERT box_office_sales(PENDING)  --23505--> renvoyer vente existante
   |                  |                              | SELECT product_prices FOR UPDATE
   |                  |                              | validate price + stock + checkin list
   |                  |                              | for each item:
   |                  |                              |------------------------------->| handle(CreateAttendeeDTO)
   |                  |                              |                                | (savepoint) Order+Item+Attendee ACTIVE
   |                  |                              |                                | increaseQuantitySold
   |                  |                              |                                | event(OrderStatusChanged, sendEmails=<D6>)
   |                  |                              |<-------------------------------| Attendee
   |                  |                              | UPDATE box_office_sales(COMPLETED)
   |                  |                              | COMMIT
   |                  |<-----------------------------| SaleResultDTO {attendees[], sale_id}
   |                  | dispatch print / return PDF
   |<-----------------|
   |  GET /.../attendees/{public_id}/ticket.pdf  (ou impression directe)
   |----------------------------------------------------------------------------->| AttendeeTicketPdfService
```

### Fichiers concernés (création / modif)
- **Nouveau** : `app/Http/Actions/BoxOffice/CreateBoxOfficeSaleAction.php`, `app/Http/Request/BoxOffice/CreateBoxOfficeSaleRequest.php`, `app/Services/Application/Handlers/BoxOffice/CreateBoxOfficeSaleHandler.php` + DTOs (`BaseDataObject`), `app/Services/Domain/BoxOffice/BoxOfficeSaleRepository` (interface + Eloquent), `app/Services/Domain/Ticket/AttendeeTicketPdfService.php`, `app/DomainObjects/Enums/BoxOfficePaymentMethod.php`, `app/Exceptions/BoxOffice*Exception.php`.
- **Modif** : `AttendeeTicketMail.php` (pointer vers `AttendeeTicketPdfService`), `ProductPriceRepositoryInterface`/impl (+ `lockForUpdateById` ou méthode générique de verrou), `routes/api.php` (route authentifiée).
- **Migrations** : `box_office_sales`, `print_jobs`, index unique `attendees.public_id` (S4).

### Tests
- Caractérisation `CreateAttendeeHandler` (vente 0 / payante / stock épuisé / product_price_id étranger).
- `CreateBoxOfficeSaleHandler` : prix refusé, écart de prix, produit sans liste, idempotence (double appel même clé), stock (2 appels concurrents → 1 seul réussit).
- Compat check-in de bout en bout (`CHECKIN_MATRIX §6`).

### Risques de régression
- **Faible** sur le code natif : `CreateAttendeeHandler` n'est pas modifié. L'extraction PDF touche `AttendeeTicketMail` (couvert par un test d'envoi).
- Verrou `FOR UPDATE` sur `product_prices` : sérialise les ventes d'un même palier — acceptable au guichet (débit humain).
- Imbrication de transactions (savepoint) : à tester explicitement (échec partiel).

### Stratégie de rollback
Feature-flag `box_office_enabled` (env/config). L'endpoint et la page sont additifs — `git revert` de la PR sans impact sur le natif. Migrations `box_office_sales`/`print_jobs` : `down()` = `dropIfExists`.

### Niveau de risque : **MOYEN-FAIBLE** · Effort relatif : **1x (référence)**

---

## Option B — Orchestrateur Box Office utilisant les primitives, sans passer par `CreateAttendeeHandler`

### Idée
`CreateBoxOfficeSaleHandler` **n'appelle pas** `CreateAttendeeHandler`. Il compose lui-même les primitives de plus bas niveau (`OrderRepository`, `AttendeeRepository`, `OrderManagementService`, `ProductQuantityUpdateService`, `TaxAndFeeRollupService`) pour produire **1 Order, N OrderItems, N Attendees** (D4-C), avec sa propre frontière transactionnelle et sa propre gestion d'événements.

### Primitives réutilisées
`OrderRepository::create`/`addOrderItem`, `AttendeeRepository::create`, `OrderManagementService::updateOrderTotals`, `ProductQuantityUpdateService::increaseQuantitySold`, `TaxAndFeeRollupService`, `IdHelper`, `OrderStatusChangedEvent`, `DomainEventDispatcherService`.

### Nouvelles responsabilités (par rapport à A)
- Reconstruire la logique de `createOrder`/`createOrderItem`/`createAttendee` (duplication maîtrisée de ~60 lignes de `CreateAttendeeHandler`) → **dette : 2 chemins de création d'attendee à maintenir**.
- Décider quels événements émettre (1 `OrderStatusChangedEvent` pour l'Order groupé, 1 `ORDER_CREATED`).
- Gérer les erreurs partielles : si l'attendee 3/4 échoue → rollback global (transaction unique) ou compensation.

### Frontière transactionnelle
```
BEGIN
  INSERT box_office_sales(PENDING, idempotency_key)            -- 23505 -> idempotent replay
  SELECT product_prices [FOR UPDATE] pour chaque palier distinct
  validate (prix serveur, stock, is_hidden, checkin list) pour chaque item
  INSERT orders (1 seul, COMPLETED, payment_provider=<mappé>, total = somme)
  for each item: INSERT order_items ; INSERT attendees (ACTIVE)
  updateOrderTotals
  for each price: increaseQuantitySold(price, qty)             -- dans la même transaction (pas la sous-transaction du service)
  UPDATE box_office_sales(COMPLETED, order_id)
COMMIT
event(OrderStatusChangedEvent(order, sendEmails=<D6>, createInvoice=<event settings>))
dispatch(OrderEvent ORDER_CREATED)
dispatch(GenerateAndPrintTicketsJob)   -- hors transaction
```

### Diagramme de séquence
```
Agent(front)     KioskAction    CreateBoxOfficeSaleHandler   OrderRepo/AttendeeRepo/QtyService   Events/Print
  | POST /events/{id}/box-office-sales {items[], buyer?, payment_method, idempotency_key}
  |----------->|
  |            | isActionAuthorized(ORGANIZER)
  |            |------------------------------->|
  |            |                               | BEGIN
  |            |                               | INSERT box_office_sales(PENDING)
  |            |                               | lock + validate all items (price/stock/hidden/checkin-list)
  |            |                               |----> OrderRepo::create (1 Order COMPLETED)
  |            |                               |----> for each: addOrderItem + Attendee::create(ACTIVE)
  |            |                               |----> updateOrderTotals
  |            |                               |----> increaseQuantitySold x N (même TX)
  |            |                               | UPDATE box_office_sales(COMPLETED)
  |            |                               | COMMIT
  |            |                               |----> event(OrderStatusChanged) ; dispatch(ORDER_CREATED)
  |            |<------------------------------| SaleResultDTO
  |            | dispatch(GenerateAndPrintTicketsJob) / return PDFs
  |<-----------|
```

### Fichiers concernés
Comme Option A **plus** : pas de dépendance à `CreateAttendeeHandler` (donc pas d'imbrication de transactions), mais **réimplémentation** de la création Order/Attendee dans `CreateBoxOfficeSaleHandler` (ou un `BoxOfficeOrderService` dédié). `ProductQuantityUpdateService` : besoin d'une variante `increaseQuantitySold` **sans** sa transaction interne (pour rester dans la transaction de l'orchestrateur) OU accepter la sous-transaction.

### Idempotence / audit
Identiques à A (`box_office_sales.idempotency_key` UNIQUE). L'audit est plus riche : 1 ligne = 1 opération = 1 Order (pas N).

### Impression après commit
Job `GenerateAndPrintTicketsJob` déclenché **après** `COMMIT`. En cas d'échec d'impression, la vente reste valide ; réimpression via D14. `print_jobs{sale_id, attendee_id, status, attempts, printed_at}`.

### Erreurs partielles
Transaction unique → **tout ou rien**. Si l'item 3 échoue (stock, prix), rien n'est créé, l'agent corrige et rejoue (même `idempotency_key` → pas de doublon car la 1re tentative a rollback y compris l'INSERT `box_office_sales`… ⚠️ **sauf si l'INSERT initial est committé à part** — à décider : soit clé idempotence en table séparée committée d'abord, soit accepter que l'échec libère la clé).

### Tests
Comme A + : Order groupé (totaux, 1 seule facture), rollback sur échec d'un item parmi N, cohérence `quantity_sold` après rollback.

### Coût de maintenance
**Plus élevé** : 2 chemins de création d'attendee (natif `CreateAttendeeHandler` + Kiosk) à garder synchrones lors des montées de version Hi.Events. Risque de divergence silencieuse (ex. upstream ajoute un champ sur Order).

### Niveau de risque : **MOYEN** · Effort relatif : **1.8x**

---

## Troisième option ?

**Aucune trouvée dans le dépôt.** Vérifié :
- `TransitionOrderToOfflinePaymentHandler` : exige une session de checkout vérifiée + Order `RESERVED` → **inadapté** à un contexte authentifié guichet (audit §A-7). Le rendre réutilisable = plus de code qu'Option B.
- Module `modules/digit/src/Bracelets/` : génération de QR HMAC signés en masse + association terrain — **concerne les bracelets pré-imprimés**, pas la vente d'un billet nominatif à un Order. Sa logique d'idempotence (`Idempotency-Key`) est un **bon patron** à imiter, pas un flux à réutiliser tel quel.
- `CompleteOrderHandler` (checkout) : suppose une session, un panier, un `payment_intent` — **inadapté**.

Aucun flux existant ne fait « vente authentifiée immédiate + billet officiel » mieux que `CreateAttendeeHandler`. On ne fabrique pas de 3ᵉ option artificielle.

---

## Comparatif

| Critère | Option A (adapter le flux manuel) | Option B (orchestrateur autonome) |
|---|---|---|
| Réutilisation du code éprouvé | **Maximale** (`CreateAttendeeHandler` intact) | Partielle (primitives bas niveau) |
| Duplication de logique | Aucune | ~60 lignes (création Order/Attendee) |
| Multi-billets, 1 Order | Non (1 Order/billet) — sauf refonte | **Oui** nativement |
| Frontière transactionnelle | Imbriquée (savepoint) — à tester | Simple, unique |
| Compat montées de version Hi.Events | **Bonne** (1 seul chemin) | Risque de divergence (2 chemins) |
| Idempotence / audit / prix / stock | mêmes garde-fous | mêmes garde-fous |
| Effort MVP | **1x** | 1.8x |
| Risque de régression natif | **Faible** | Faible (natif non touché) mais surface de bugs propre plus large |
| Rollback | feature-flag + revert | feature-flag + revert |

---

## Recommandation

**Option A pour le MVP**, avec la trajectoire suivante :

1. **Slice 1** (voir `FIRST_SLICE`) : Option A, **1 billet par opération** (D4-A/B), garde-fous prix + stock + idempotence + check-list, PDF via service extrait, impression par dialogue navigateur.
2. Si le besoin « 1 Order pour N billets » (comptabilité, facture unique famille) se confirme → **évoluer vers B** en isolant la création dans un `BoxOfficeOrderService` partagé, testé, qui devient l'unique chemin (et vers lequel `CreateAttendeeHandler` pourrait à terme déléguer).

**Preuves à l'appui** :
- `CreateAttendeeHandler` produit déjà exactement les entités officielles + le QR natif (audit §C) → réutilisation directe = moins de surface de bug.
- Les 3 risques critiques (prix S1, stock S2, idempotence S3) se traitent **au-dessus** du handler, sans le modifier.
- Le seul manque structurel d'Option A (multi-billets/1 Order) n'est **pas** un risque de sécurité, seulement une commodité comptable → reportable.
- Option B double le coût de maintenance pour un bénéfice non critique au MVP.
