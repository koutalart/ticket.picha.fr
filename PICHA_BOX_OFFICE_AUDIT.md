# PICHA Box Office — Audit en lecture seule

Date : 3 septembre 2026
Méthode : commandes exécutées par Jo sur l'hôte, sorties analysées par Claude. Aucune modification du dépôt.
Catégories : **CONFIRMÉ** (preuve citée) · **RÉFUTÉ** · **NON DÉTERMINÉ** · **DÉCISION REQUISE**

---

## 1. État du dépôt

| Élément | Valeur | Preuve |
|---|---|---|
| Chemin | `/opt/digit-ticket` | `pwd` |
| Branche | `staging` | `git branch --show-current` |
| HEAD | `2aa3ee8` — 2026-07-18 « feat(digit): freeze Device Registry B2-B5 before Somaroho » | `git log -1` |
| Remotes | `origin` = koutalart/ticket.picha.fr ; `upstream` = HiEventsDev/Hi.Events | `git remote -v` |
| Working tree | **35 fichiers modifiés, 66 non suivis, 13 fichiers vides parasites à la racine** | `git status --short`, `find . -maxdepth 1 -size 0` |
| Instructions projet | `CLAUDE.md` (conventions Hi.Events), `AGENTS.md` (renvoie à CLAUDE.md) | `cat` |

**CONFIRMÉ — le code qui a tourné à Somaroho n'est pas commité.** Les modifications de juillet-août (QrScanner, SelectProducts, AttendeeTicketMail + PDF, docker-compose.staging.yml, fr.json, ScanOutcomeDTO, composer.json) n'existent que dans le working tree, avec ~30 fichiers `.bak-2026073x`.

**CONFIRMÉ — 13 fichiers vides à la racine** (`->extractToken($request);`, `5,`, `{`, `}`…). Signature d'un collage de code PHP dans le shell ; chaque `>` a créé un fichier. Sans effet, à supprimer avant commit.

---

## 2. Version et divergence du fork

| Élément | Valeur | Preuve |
|---|---|---|
| Version Hi.Events | **1.10.0-beta** | `VERSION`, `backend/composer.json`, `frontend/package.json` (concordants) |
| Tag amont dans le fork | `v.1.10.0-beta` | `git tag` |
| Commits DIGIT au-dessus | **9** | `git log v.1.10.0-beta..HEAD` |
| Périmètre des 9 commits | Scan/Devices/Bracelets, infra staging, nginx | messages de commit |

**CONFIRMÉ** : aucun commit ne touche Order, Attendee, paiement ou check-in natif. Le working tree touche `AttendeeTicketMail.php`, `composer.json` (ajout `simplesoftwareio/simple-qrcode`) et `AccountConfigurationDomainObject.php` (1 ligne).

---

## 3. Architecture observée

| Couche | Observé | Preuve |
|---|---|---|
| Backend | Laravel 12, PHP ^8.2 | `composer.json` |
| Frontend | React 18, TypeScript 5.8, Vite 5, Mantine 8, SSR | `package.json`, `CLAUDE.md` |
| DB / cache | PostgreSQL, Redis | `docker-compose.staging.yml` services |
| Compose | postgres, redis, backend, queue-worker, scheduler, frontend ; volume `backend_storage` | idem |
| Flux backend | Action → Handler (`app/Services/Application/Handlers`) → Service (`app/Services/Domain`) → Repository | `CLAUDE.md` + arborescence |
| Module custom | `backend/modules/digit/` (Scan, Devices, Bracelets) ; `frontend/src/pages/digit/` | `find` |
| Rôles | `SUPERADMIN`, `ADMIN`, `ORGANIZER` uniquement | `app/DomainObjects/Enums/Role.php` |
| Autorisation | `BaseAction::isActionAuthorized($entityId, $entityType, Role $min = ORGANIZER)` via `IsAuthorizedService`, cloisonné par compte | `BaseAction.php:160-176` |

Conventions imposées par `CLAUDE.md` qui contraignent Box Office : IDs entiers auto-incrémentés (pas d'UUID en clé primaire), DTO Spatie `BaseDataObject`, exceptions custom, `DatabaseTransactions` en test, Mantine + SCSS modules, Lingui, `showSuccess`/`showError`.

**DÉCISION (Jo, 3 sept.)** : contrôle d'accès via le scanner natif Hi.Events. Le module DIGIT Scan (Device Registry, tokens, `assign-lists`) est hors périmètre. Exception : la migration `2026_07_18_000001_add_unique_constraint_to_attendee_check_ins` est conservée (elle protège le check-in natif).

---

## 4. Cartographie des flux

### 4.1 Création manuelle d'attendee — flux candidat n°1

`POST /events/{event_id}/attendees` → `CreateAttendeeAction` → `CreateAttendeeHandler` (`app/Services/Application/Handlers/Attendee/`).

Comportement **CONFIRMÉ** par lecture du handler :

1. transaction ouverte ;
2. `orderRepository->create(...)` avec `status = COMPLETED`, `payment_status = NO_PAYMENT_REQUIRED` si montant 0 sinon `PAYMENT_RECEIVED`, `is_manually_created = true`, `short_id`/`public_id` via `IdHelper` ;
3. chargement du produit (`product_type = TICKET`, même `event_id`) ; sinon `NoTicketsAvailableException` ;
4. `getQuantityRemainingForProductPrice` ; si ≤ 0 → `NoTicketsAvailableException` ;
5. un `OrderItem` quantité 1, prix = `amount_paid` fourni par le client ;
6. un `Attendee` `ACTIVE` ;
7. `updateOrderTotals` ;
8. `increaseQuantitySold(product_price_id)` ;
9. `OrderStatusChangedEvent(sendEmails: $send_confirmation_email)` ;
10. webhook `ORDER_CREATED`.

Contrat d'entrée (`CreateAttendeeRequest`) : `product_id`, `product_price_id`, **`email` obligatoire**, `first_name`, `last_name`, `amount_paid`, `send_confirmation_email`, `locale`, `taxes_and_fees` optionnel.

Limites **CONFIRMÉES** :
- un appel = un billet ;
- `amount_paid` n'est pas confronté au prix du produit ;
- aucune question de checkout n'est posée ni stockée ;
- pas de `payment_provider` renseigné sur l'Order ;
- l'Order naît `COMPLETED` : le schéma « offline puis mark-as-paid » n'est **pas** possible avec ce flux ;
- `createAttendee` et `increaseQuantitySold` lisent `$attendeeDTO->product_price_id` (pas la valeur résolue par `getProductPriceId`). Sans effet tant que l'id est fourni — le Request l'exige — mais un orchestrateur doit toujours l'envoyer.

### 4.2 Paiement offline et marquage payé

- `TransitionOrderToOfflinePaymentHandler` : exige une session de checkout vérifiée et un Order `RESERVED` non expiré. **RÉFUTÉ comme réutilisable au guichet.**
- `MarkOrderAsPaidService::markOrderAsPaid($orderId, $eventId)` : exige `status = AWAITING_OFFLINE_PAYMENT`, sinon `ResourceConflictException`. Passe l'Order en `COMPLETED`/`PAYMENT_RECEIVED`, les attendees `AWAITING_PAYMENT` → `ACTIVE`, met à jour la facture, incrémente l'affilié, crée un `OrderApplicationFee` avec `PaymentProviders::OFFLINE`, envoie le récapitulatif client. **CONFIRMÉ**, mais utilisable seulement si un Order a été créé en `AWAITING_OFFLINE_PAYMENT` — ce qu'aucun flux authentifié ne fait aujourd'hui.

### 4.3 Génération QR et billet

- Hi.Events natif : le QR est rendu côté frontend à partir de `attendee.public_id`. Aucune génération serveur dans le code commité (grep `QrCode` : uniquement `AttendeeTicketMail.php` modifié).
- Working tree (Somaroho) : `AttendeeTicketMail::generateTicketPdf()` (privée) encode `public_id` en PNG via `SimpleSoftwareIO\QrCode`, rend `resources/views/attendee-ticket-pdf.blade.php` via `Barryvdh\DomPDF`, joint le PDF à l'email. **CONFIRMÉ.**
- **Il n'existe aucune signature du QR** : la valeur scannée est le `public_id` en clair. Ce n'est pas un défaut du guichet, c'est le modèle Hi.Events.

### 4.4 Check-in natif

`POST /check-in-lists/{list_short_id}/check-ins` → `CreateAttendeeCheckInPublicAction` → `CreateAttendeeCheckInService`. Règles **CONFIRMÉES** :

1. liste dans sa fenêtre `activates_at`/`expires_at` ;
2. attendee résolu par `public_id` ;
3. `verifyAttendeeBelongsToCheckInList` : `attendee.product_id ∈ checkInList.products` (`CheckInListDataService.php:28-42`) ;
4. doublon : lecture des check-ins existants puis écriture — la contrainte unique DIGIT sur `attendee_check_ins` ferme la fenêtre de course ;
5. `CANCELLED` refusé ; `AWAITING_PAYMENT` refusé sauf `allow_orders_awaiting_offline_payment_to_check_in` ;
6. action `CHECK_IN_AND_MARK_ORDER_AS_PAID` disponible si le réglage le permet.

**Condition complète pour qu'une vente guichet soit scannable** : attendee `ACTIVE` + `product_id` présent dans une check-in list de l'événement dont la fenêtre est ouverte. Rien d'autre.

### 4.5 Stock et concurrence

`ProductRepository::getQuantityRemainingForProductPrice` (SQL, lecture) puis `ProductQuantityUpdateService::increaseQuantitySold` (`quantity_sold + 1`, dans sa propre transaction). **CONFIRMÉ : aucun verrou.** Deux créations manuelles simultanées sur le dernier billet réussissent toutes les deux.

### 4.6 Recherche d'attendee

`GET /events/{event_id}/attendees` avec `filter_fields` (`AttendeeRepository.php:84`). Colonnes disponibles : `first_name`, `last_name`, `email`, `public_id`, `short_id`, `notes`. **NON DÉTERMINÉ** : le détail des champs interrogeables et le comportement de la recherche plein texte n'ont pas été lus. **Pas de colonne téléphone** — une recherche par téléphone suppose de passer par les réponses aux questions.

### 4.7 Frontend

`components/modals/CreateAttendeeModal` + `mutations/useCreateAttendee.ts` existent (non lus). Convention React Query / Mantine / Lingui confirmée par `CLAUDE.md`. **NON DÉTERMINÉ** : contenu du modal, gestion des erreurs, réutilisabilité en mode tactile.

### 4.8 Flux non tracés

Achat public payant (Stripe), commande gratuite publique, remboursement/annulation, invoices : non nécessaires pour le MVP tel que le flux manuel l'oriente. À tracer seulement si la DÉCISION 2 (ci-dessous) impose un Order en attente de paiement.

---

## 5. Tableau des preuves

| Élément | Statut | Preuve |
|---|---|---|
| Version Hi.Events | CONFIRMÉ | `VERSION`, composer/package.json, tag `v.1.10.0-beta`, 9 commits |
| Chemin dépôt | CONFIRMÉ | `pwd` |
| Stack | CONFIRMÉ | manifests, compose |
| Création d'attendee/Order manuelle | CONFIRMÉ | `CreateAttendeeAction.php`, `CreateAttendeeHandler.php`, `CreateAttendeeRequest.php`, `CreateAttendeeDTO.php` |
| Prix serveur | RÉFUTÉ (non validé) | `CreateAttendeeHandler::createOrderItem` utilise `amount_paid` client |
| Verrou stock | RÉFUTÉ (absent) | `ProductRepository.php:60-90`, `ProductQuantityUpdateService.php:28-43` |
| Paiement offline réutilisable | RÉFUTÉ | `TransitionOrderToOfflinePaymentHandler::validateOfflinePayment` (session + RESERVED) |
| Mark-as-paid | CONFIRMÉ (précondition AWAITING_OFFLINE_PAYMENT) | `MarkOrderAsPaidService.php` |
| QR | CONFIRMÉ | `AttendeeTicketMail.php` (diff), `attendee-ticket-pdf.blade.php` |
| Check-in + anti-double-scan | CONFIRMÉ | `CreateAttendeeCheckInService.php`, `CheckInListDataService.php:28-42`, migration DIGIT contrainte unique |
| Association produit/liste | CONFIRMÉ | `CheckInListDataService.php:33-35` |
| Permissions | CONFIRMÉ | `Role.php`, `BaseAction.php:160-176` |
| Impression | CONFIRMÉ partiel | PDF existant dans le Mailable ; aucun mécanisme d'impression |
| Idempotence | RÉFUTÉ (absent) | grep sans résultat ; aucun middleware, aucune contrainte |
| Tests existants | RÉFUTÉ (absents) | `find tests` : rien sur CreateAttendee ni MarkOrderAsPaid |

---

## 6. Éléments confirmés (synthèse)

- Le flux manuel `CreateAttendeeHandler` produit exactement les entités officielles attendues (Order + OrderItem + Attendee ACTIVE) et le QR natif (`public_id`).
- Un attendee ainsi créé est scannable immédiatement par le scanner natif si son produit est dans une liste active.
- Une base PDF + QR serveur existe déjà (Somaroho), non commitée.
- Le check-in natif est protégé contre le double scan au niveau DB grâce à la migration DIGIT.

## 7. Éléments réfutés

- Pas de mécanisme d'idempotence.
- Pas de verrou sur le stock dans le flux manuel.
- Pas de validation serveur du prix dans le flux manuel.
- Pas de permissions plus fines que ORGANIZER/ADMIN.
- Le flux offline public n'est pas réutilisable.
- Aucun test sur les briques critiques.

## 8. Éléments non déterminés

- Champs réellement interrogeables dans la recherche d'attendees.
- Existence de `barryvdh/laravel-dompdf` dans le `composer.json` de base (probable, utilisé pour les factures) — à vérifier.
- Détail de `CreateAttendeeModal` côté frontend.
- Comportement de `OrderStatusChangedEvent` avec `sendEmails: false` (l'email est-il le seul effet ?).
- Existence d'un mécanisme de réponses aux questions utilisable hors checkout (`QuestionAnswer` repository) pour stocker téléphone/organisation.

## 9. Composants réutilisables

| Composant | Usage Box Office |
|---|---|
| `CreateAttendeeHandler` | création Order + Attendee, appelé depuis l'orchestrateur |
| `ProductRepository::getQuantityRemainingForProductPrice` | affichage du stock et contrôle |
| `CheckInListRepository` / relation `products` | vérification « produit rattaché à une liste » |
| `AttendeeRepository` + `filter_fields` | recherche de doublons |
| `generateTicketPdf` (à extraire) | driver PDF |
| `BaseAction::isActionAuthorized` | autorisation par compte et rôle |
| `ResourceConflictException`, `ValidationException` | erreurs 409/422 conformes |
| `CreateAttendeeModal` (à évaluer) | base du formulaire guichet |

## 10. Capacités manquantes

1. Idempotence des créations.
2. Verrou de stock.
3. Validation serveur du prix (produit + prix sélectionné = montant).
4. Traçabilité du moyen de paiement (espèces / TPE / gratuit) : aucun champ sur l'Order du flux manuel.
5. Multi-billets par vente.
6. Service de génération PDF réutilisable hors email.
7. Toute notion d'impression, de job d'impression, de réimpression.
8. Toute notion de session de caisse.
9. Tests de caractérisation.

## 11. Risques

- **Baseline non commitée** : toute branche Box Office créée maintenant embarque Somaroho. Risque de mélange et de rollback impossible.
- **Survente sur le dernier billet** (course confirmée).
- **Email obligatoire** dans le contrat natif : un guichet sans email doit soit générer une adresse technique (à auditer), soit modifier le Request.
- **`send_confirmation_email`** : à `false`, aucun billet n'est envoyé ; à `true`, un email part avec le PDF — le comportement doit être un choix explicite de l'agent.
- **QR non signé** : un `public_id` connu suffit à scanner. Modèle Hi.Events, mais à garder en tête pour les badges imprimés.
- **Package DomPDF** : si absent du composer de base, dépendance à ajouter proprement.

## 12. Décisions nécessaires

| # | Décision | Options | Impact |
|---|---|---|---|
| D1 | Figer Somaroho avant Box Office | commit + tag `digit-staging-somaroho-2026` / ne rien faire | conditionne la création de branche |
| D2 | Moment de l'encaissement | (a) encaissement **avant** validation → Order `COMPLETED` via handler existant ; (b) Order `AWAITING_OFFLINE_PAYMENT` puis mark-as-paid → nouveau handler ou paramétrage du handler existant | (a) = MVP minimal ; (b) = plus de code, permet « billet remis puis paiement » |
| D3 | Traçabilité du moyen de paiement | colonne(s) sur `orders` / table `box_office_sales` / `notes` | rapports et audit |
| D4 | Multi-billets | un Order par billet (boucle) / nouveau handler multi-items | UX guichet |
| D5 | Email non fourni | adresse technique générée / rendre `email` nullable | contrat natif |
| D6 | Téléphone / organisation | réponses aux questions / `notes` / hors MVP | recherche de doublons |
| D7 | Produit sans check-in list | bloquer / avertir | UX et sécurité terrain |
| D8 | Permissions | ORGANIZER pour vendre, ADMIN pour réimprimer / tout ORGANIZER | conforme au modèle existant |
| D9 | Idempotence | clé unique string sur table de projection + hash / autre | implémentation |
| D10 | Impression MVP | PDF via service extrait + dialogue navigateur / autre | driver de test |
| D11 | Session de caisse | oui / non pour le MVP | tables à créer |

## 13. Proposition de MVP fondée sur l'audit

Sous réserve de D1 à D11 :

- **Orchestrateur** `CreateBoxOfficeSaleHandler` qui, par billet : verrouille la ligne `product_prices` (`lockForUpdate`), vérifie le prix serveur, vérifie l'appartenance à une check-in list, appelle `CreateAttendeeHandler` avec `send_confirmation_email` choisi par l'agent, enregistre la trace guichet, commit, puis déclenche l'impression hors transaction.
- **Idempotence** par ligne de projection insérée en premier (clé unique) — conforme à « IDs entiers » puisque la clé est une colonne, pas la PK.
- **Impression** : extraction de `generateTicketPdf` en `AttendeeTicketPdfService`, endpoint authentifié renvoyant le PDF, impression via dialogue navigateur ; `print_jobs` minimal.
- **Frontend** : page `/manage/event/:eventId/box-office` réutilisant les composants Mantine, formulaire dérivé de `CreateAttendeeModal`.
- **Tests** d'abord : caractérisation de `CreateAttendeeHandler` (vente 0, vente payante, stock épuisé), puis concurrence, idempotence, check-in de bout en bout.

**Arrêt ici. Aucune branche ni modification tant que D1–D11 ne sont pas tranchées.**
