# PICHA Box Office / Kiosk — Premier parcours vertical (slice 1)

Date : 3 septembre 2026 · **Aucun code écrit.** Ce document définit le plus petit parcours démontrable et les tests à écrire **avant** l'implémentation.
Conditionné à : baseline figée (D1) + validation métier de D2, D3, D6, D9, D11, D13, D14, D18 (les autres peuvent rester ouvertes pour le slice 1).

> **Décisions PICHA ratifiées le 3 sept. 2026** (cf. `PICHA_BOX_OFFICE_DECISIONS_REQUIRED.md`, section « ratifiées ») :
> D2=A · D3=A (`box_office_sales.payment_method` ∈ {CASH,CARD,FREE}, `amount_collected` séparé) · D6=D (e-mail optionnel, `attendees.email = NULL`, jamais fictif ; B obligatoire annulée le 7 sept. 2026) · D9=A (`idempotency_key` UNIQUE, insérée 1re) · D11=B (bloquer si produit non scannable) · D13/D14=A (ORGANIZER vend+réimprime, `print_jobs`) · D18=A (maquette existante) · S4 inclus dans les migrations du slice 1 · S9 clos.
> **Tolérance de prix = STRICTE** : `amount` serveur = `product_prices.price`, aucun écart. AC-2 devient une **égalité stricte**.
> **Impression** : PDF + `window.print()`, abstraction `TicketRenderer` (PDF | ZPL), **aucun ZPL** au slice 1.
> **D19 tranchée (5 sept. 2026)** : poste agent = macOS (dev + pilote, cible Windows ensuite), liaison ZD621 = réseau/IP en principal (USB en fallback) — sans impact sur le code du slice 1 (fixe la direction du futur driver ZPL réseau, hors slice 1).

---

## 1. Périmètre du slice 1

**Inclus**
- 1 agent `ORGANIZER` authentifié, sur 1 événement de test, données fictives.
- Vente d'**un** billet à la fois (pas de multi-billets).
- Produit + palier validés **côté serveur** (prix = `product_prices.price`, pas de valeur client).
- Vérification « produit rattaché à une check-in list active » **avant** création (D11).
- Idempotence par `idempotency_key` (D9).
- Création via `CreateAttendeeHandler` (Option A) → Order `COMPLETED`, Attendee `ACTIVE`, QR natif.
- Génération d'un **PDF de test** via `AttendeeTicketPdfService` (extraction de `AttendeeTicketMail::generateTicketPdf`).
- Impression : **dialogue d'impression navigateur** sur le PDF (pas de pilote ZPL).
- Traçabilité minimale : `box_office_sales{idempotency_key, agent_user_id, payment_method, amount_collected, order_id, attendee_id, status}`.
- Réimpression : endpoint qui **relit le même attendee** et renvoie le PDF, trace dans `print_jobs`. **Jamais** de nouvel Order/Attendee.
- Check-in de bout en bout vérifié par test (1er scan OK, 2e scan neutralisé).

**Exclus du slice 1** (backlog)
- Multi-billets / 1 Order groupé.
- Téléphone / organisation / questions `required` (D7, D8).
- Session de caisse (D15), rapport de fin de service (D16).
- Mode hors-ligne (D17 = online-only).
- Pilote ZPL / connexion IP imprimante (D19).
- Correction du 500 concurrent sur le check-in natif (S5) — sauf si le slice pilote lui-même le scan (il ne le fait pas ici : le scan reste l'app native).
- `email` nullable (D6 = option D, e-mail optionnel au guichet).

---

## 2. Parcours

```
Agent authentifié (ORGANIZER)
  -> ouvre /manage/event/:eventId/box-office
  -> l'écran liste les produits TICKET vendables (status actif, non hidden, rattachés à une check-in list active)
     [si un produit n'est rattaché à aucune liste active -> non listé + info]
  -> sélectionne 1 produit + 1 palier
     -> le prix serveur s'affiche (lecture seule), le stock restant s'affiche
  -> saisit prénom, nom, email (obligatoire), langue
  -> choisit le moyen de paiement encaissé (CASH / CARD / FREE)
  -> [détection doublon: si un attendee même email/nom existe -> alerte non bloquante + bouton "réimprimer l'existant"]
  -> clique "Encaisser et créer le billet"
     front génère un idempotency_key (UUID v4) pour CETTE opération
  -> POST /events/:eventId/box-office-sales
       { product_id, product_price_id, first_name, last_name, email, locale,
         payment_method, amount_collected, send_confirmation_email:false, idempotency_key }
  <- 201 { sale_id, attendee: { public_id, short_id, ... }, order: { public_id } }
  -> le front ouvre le PDF (GET /events/:eventId/attendees/:publicId/ticket.pdf) et déclenche window.print()
  -> l'agent imprime sur la ZD621 via le dialogue navigateur
  -> (plus tard) réimpression: POST /events/:eventId/attendees/:publicId/reprint  -> renvoie le PDF, trace print_jobs

Contrôle d'accès (app native, inchangée)
  -> le porteur se présente au portique
  -> l'agent de scan scanne le QR (= attendee.public_id) via l'app native
  -> 1er scan: accepté (recorded)
  -> 2e scan: "déjà scanné"
```

---

## 3. Surface technique (rappel, détail dans DESIGN_OPTIONS Option A)

| Élément | Type |
|---|---|
| `CreateBoxOfficeSaleAction` + `CreateBoxOfficeSaleRequest` | nouveau |
| `CreateBoxOfficeSaleHandler` + DTOs (`BaseDataObject`) | nouveau |
| `BoxOfficeSaleRepositoryInterface` + impl Eloquent | nouveau |
| `AttendeeTicketPdfService` | extraction depuis `AttendeeTicketMail` |
| `GetBoxOfficeTicketPdfAction`, `ReprintBoxOfficeTicketAction` | nouveau |
| `BoxOfficePaymentMethod` enum (`CASH`,`CARD`,`FREE`) | nouveau |
| `ProductPriceRepository` : verrou `FOR UPDATE` par id | ajout méthode |
| exceptions : `BoxOfficePriceMismatchException`, `ProductNotScannableException` | nouveau |
| migrations : `box_office_sales`, `print_jobs`, index unique `attendees.public_id` (S4) | nouveau |
| route authentifiée `POST /events/{event_id}/box-office-sales` etc. | `routes/api.php` |
| Front : page `/manage/event/:eventId/box-office`, `useCreateBoxOfficeSale` (mutation), `useGetBoxOfficeProducts` (query) | nouveau, Mantine + SCSS modules + Lingui |

---

## 4. Critères d'acceptation mesurables

### Vente
- [ ] **AC-1** Un `ORGANIZER` du compte propriétaire peut créer une vente ; un utilisateur d'un autre compte reçoit 403 (`isActionAuthorized`).
- [ ] **AC-2** Le montant enregistré sur l'`OrderItem.price` et `Order.total_gross` est **exactement** `product_prices.price` du palier choisi (+ taxes serveur). **Tolérance STRICTE** : si le client envoie un `amount` ≠ `product_prices.price` → **rejet 422** (`BoxOfficePriceMismatchException`), aucune écriture. `amount_collected` (moyen de paiement) est un champ distinct, non contraint à l'égalité.
- [ ] **AC-3** `amount_collected` (moyen de paiement encaissé) est stocké dans `box_office_sales`, distinct du prix.
- [ ] **AC-4** `payment_method` ∈ {CASH, CARD, FREE} stocké ; FREE ⇒ `Order.payment_status = NO_PAYMENT_REQUIRED`.
- [ ] **AC-5** L'attendee créé a `status = ACTIVE`, un `public_id` non nul, `product_price_id` = celui envoyé (non nul).
- [ ] **AC-6** `product_prices.quantity_sold` est incrémenté de 1 exactement (pas 0, pas 2).
- [ ] **AC-7** Aucun mail n'est envoyé (`send_confirmation_email = false` imposé par le slice).

### Stock
- [ ] **AC-8** Sur le dernier billet (`quantity_available - quantity_sold = 1`), deux ventes **concurrentes** → **une** réussit (201), l'autre échoue proprement (409 « plus de stock »). `quantity_sold` final = `quantity_available`. *(test S2)*
- [ ] **AC-9** Vente d'un produit épuisé → 409, aucune écriture.

### Idempotence
- [ ] **AC-10** Deux `POST` **séquentiels** avec le **même** `idempotency_key` → le 2e renvoie la **même** vente (même `sale_id`, même `attendee.public_id`), **sans** créer de 2e Order/Attendee, **sans** ré-incrémenter le stock.
- [ ] **AC-11** Deux `POST` **concurrents** avec le même `idempotency_key` → une seule vente créée (violation d'unicité catchée).
- [ ] **AC-12** Deux `POST` avec des `idempotency_key` **différents** et mêmes données → 2 ventes (comportement attendu : ce n'est pas une déduplication de contenu).

### Produit scannable (D11)
- [ ] **AC-13** Vente d'un produit **non rattitré à une check-in list active** → 409 `ProductNotScannableException`, aucune écriture.
- [ ] **AC-14** Le produit vendu, une fois l'attendee créé, est **accepté au 1er scan** sur la liste correspondante (test de bout en bout).

### QR / identifiant
- [ ] **AC-15** Le QR du PDF encode **exactement** `attendee.public_id` (chaîne, pas d'enveloppe/signature) — cohérent avec le check-in natif.
- [ ] **AC-16** `attendees.public_id` est unique en base (index unique en place) ; le générateur retente sur collision (test avec générateur mocké renvoyant 2× la même valeur).

### PDF / impression
- [ ] **AC-17** `GET /events/:eventId/attendees/:publicId/ticket.pdf` renvoie un `application/pdf` non vide, contenant nom, événement, type de billet, QR, `public_id` en clair.
- [ ] **AC-18** L'extraction de `AttendeeTicketPdfService` ne change pas le contenu du PDF joint au mail natif (test de non-régression sur `AttendeeTicketMail`).
- [ ] **AC-19** Le PDF se rend sans erreur `gd`/police (accents FR corrects).

### Réimpression (D14)
- [ ] **AC-20** `POST /events/:eventId/attendees/:publicId/reprint` renvoie le PDF du **même** attendee, **ne crée aucun** Order/Attendee, n'incrémente pas le stock.
- [ ] **AC-21** Chaque réimpression insère une ligne `print_jobs{attendee_id, agent_user_id, printed_at, reason?}`.
- [ ] **AC-22** La réimpression est autorisée à tout `ORGANIZER` du compte ; 403 sinon.

### Check-in de bout en bout
- [ ] **AC-23** `POST /public/check-in-lists/{short_id}/check-ins` avec le `public_id` de la vente → 200, 1 ligne `attendee_check_ins`, `errors == []`.
- [ ] **AC-24** Rejeu séquentiel du même scan → 200, `errors[0]` ~ « already checked in », toujours 1 seule ligne.
- [ ] **AC-25** (documenté, non bloquant slice 1) rejeu **concurrent** → 1×200 + 1×500 (limite S5 connue).

### Transactionnel
- [ ] **AC-26** Si `CreateAttendeeHandler` échoue (ex. `product_price_id` invalide injecté), **aucune** ligne `box_office_sales` en statut COMPLETED ne subsiste (soit absente, soit `FAILED`), `quantity_sold` inchangé.

---

## 5. Liste exacte des tests à écrire (AVANT implémentation)

> Tous en `DatabaseTransactions` (pas `RefreshDatabase`), TestCase Laravel, Mockery. Dossier `backend/tests/`.

### 5.1 Caractérisation de l'existant (à écrire en premier, sur le code actuel)
`tests/Unit/BoxOffice/CreateAttendeeHandlerCharacterizationTest.php`
1. `test_manual_sale_zero_amount_creates_no_payment_required_order`
2. `test_manual_sale_paid_amount_sets_payment_received`
3. `test_manual_sale_amount_is_taken_verbatim_from_client` *(documente S1)*
4. `test_manual_sale_negative_amount_is_rejected_by_validation`
5. `test_manual_sale_product_price_id_from_other_product_is_rejected`
6. `test_manual_sale_out_of_stock_throws_no_tickets_available`
7. `test_manual_sale_increments_quantity_sold_by_one`
8. `test_manual_sale_emits_order_status_changed_with_send_emails_flag` *(spy sur l'event)*

`tests/Unit/BoxOffice/StockRaceCharacterizationTest.php`
9. `test_two_sequential_sales_on_last_ticket_second_fails` *(baseline)*
10. `test_two_concurrent_sales_on_last_ticket_currently_oversell` *(S2 — 2 connexions PDO / process ; doit ÉCHOUER aujourd'hui = preuve du bug)*

`tests/Feature/BoxOffice/NativeCheckInCompatibilityTest.php` *(cf. `CHECKIN_MATRIX §6`)*
11. `test_manually_created_attendee_is_accepted_on_first_scan`
12. `test_second_sequential_scan_is_rejected_without_duplicate_row`
13. `test_second_concurrent_scan_returns_500` *(documente S5)*
14. `test_attendee_whose_product_not_on_list_is_rejected`

### 5.2 Tests du nouvel orchestrateur (rouges tant que non implémenté — TDD)
`tests/Unit/BoxOffice/CreateBoxOfficeSaleHandlerTest.php`
15. `test_price_is_resolved_server_side_and_client_amount_ignored` → AC-2
16. `test_price_mismatch_beyond_tolerance_is_rejected` → AC-2
17. `test_free_payment_method_sets_no_payment_required` → AC-4
18. `test_amount_collected_stored_separately_from_price` → AC-3
19. `test_product_without_active_checkin_list_is_rejected` → AC-13
20. `test_out_of_stock_returns_conflict` → AC-9
21. `test_successful_sale_creates_order_attendee_and_box_office_sale_row` → AC-5, AC-26
22. `test_quantity_sold_incremented_exactly_once` → AC-6
23. `test_no_email_sent_when_send_confirmation_email_false` → AC-7
24. `test_partial_failure_rolls_back_box_office_sale` → AC-26

`tests/Unit/BoxOffice/BoxOfficeIdempotencyTest.php`
25. `test_same_key_sequential_returns_same_sale` → AC-10
26. `test_same_key_concurrent_creates_single_sale` → AC-11
27. `test_different_keys_create_distinct_sales` → AC-12

`tests/Unit/BoxOffice/BoxOfficeStockLockTest.php`
28. `test_two_concurrent_sales_on_last_ticket_only_one_succeeds` → AC-8 *(doit PASSER avec le verrou)*

`tests/Unit/BoxOffice/AttendeePublicIdUniquenessTest.php`
29. `test_public_id_has_unique_index` *(schéma)* → AC-16
30. `test_generator_retries_on_collision` *(IdHelper/générateur mocké)* → AC-16

`tests/Unit/Ticket/AttendeeTicketPdfServiceTest.php`
31. `test_pdf_contains_public_id_and_qr_encodes_public_id` → AC-15, AC-17
32. `test_pdf_renders_french_accents` → AC-19
33. `test_extracted_service_produces_same_output_as_mail_attachment` → AC-18

`tests/Feature/BoxOffice/ReprintTest.php`
34. `test_reprint_returns_same_attendee_pdf_without_new_order` → AC-20
35. `test_reprint_records_print_job` → AC-21
36. `test_reprint_forbidden_for_other_account` → AC-22

`tests/Feature/BoxOffice/BoxOfficeAuthorizationTest.php`
37. `test_organizer_can_create_sale` → AC-1
38. `test_other_account_user_gets_403` → AC-1

`tests/Feature/BoxOffice/BoxOfficeEndToEndTest.php`
39. `test_full_slice_sale_then_pdf_then_first_scan_then_second_scan` → AC-14, AC-23, AC-24

### 5.3 Front
40. `tsc --noEmit` vert.
41. Test composant : la page n'affiche pas les produits non scannables ; le prix est en lecture seule ; le bouton se désactive pendant la mutation ; `idempotency_key` régénéré à chaque ouverture du formulaire.

---

## 6. Définition de « prêt à développer le slice 1 »

Toutes les cases doivent être cochées :

- [x] D2, D3, D6, D9, D11, D13, D14, D18 validées par PICHA (3 sept. 2026).
- [x] Tolérance de prix : **STRICTE** (AC-2 = égalité).
- [x] Impression slice 1 : **PDF + `window.print()`**, abstraction `TicketRenderer`, pas de ZPL.
- [x] S4 : index unique `attendees.public_id` inclus dans les migrations du slice 1.
- [x] D1 exécutée : tag `digit-staging-somaroho-2026` créé par Jo le 2026-09-04 sur le commit `2809a04b`, branche `feat/picha-kiosk` créée depuis ce tag (vérifié : `git log digit-staging-somaroho-2026..HEAD` ne montre que les commits post-baseline de cette session).
- [x] Conteneur `docker/development` opérationnel avec base de test isolée **confirmée** (données fictives) — démarré et vérifié le 2026-09-04 (Postgres/Redis/Mailpit isolés de la stack staging qui tournait par ailleurs sous les mêmes noms `digit-ticket-*` ; voir `PICHA_BASELINE_TODO.md`).
- [x] Tests 1–14 (caractérisation) écrits et exécutés le 2026-09-04 : 11 verts / 3 rouges (S1, S2, S5) — voir le tableau dans la session ou rejouer `docker compose -f docker/development/docker-compose.dev.yml exec backend php artisan test --filter=BoxOffice`.
- [x] D19 tranchée par Jo le 2026-09-05 : poste agent **macOS** (dev + pilote, cible Windows ensuite), liaison ZD621 **réseau/IP en principal, USB en fallback**. Sans impact sur le code du slice 1 (voir `PICHA_BOX_OFFICE_DECISIONS_REQUIRED.md` §D19) — fixe la direction du futur driver ZPL réseau, hors slice 1.

Toutes les cases sont cochées : **le code applicatif Kiosk (slice 1, Option A) peut démarrer.**
