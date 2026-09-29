# PICHA Box Office / Kiosk — Décisions métier requises

Date : 3 septembre 2026 · Aucune décision n'est prise ici. Chaque ligne attend une validation explicite de Jo / PICHA.
Les recommandations sont fondées sur l'audit (`PICHA_BOX_OFFICE_AUDIT_v2.md`) et les constats de sécurité (`PICHA_BOX_OFFICE_SECURITY_FINDINGS.md`).

Légende impact : **[Baseline]** conditionne la création de branche · **[Code]** volume de dev · **[Schéma]** migration · **[UX]** parcours agent · **[Compta]** rapports/factures · **[Sécu]**.

---

## Décisions ratifiées par PICHA — 3 septembre 2026 (slice 1)

| # | Décision retenue |
|---|---|
| D1 | **Faite** — baseline figée, tag `digit-staging-somaroho-2026` (création du tag hors session de stabilisation, sur le poste de Jo, après exécution des tests). |
| D2 | **A** — encaissement **avant** validation, Order `COMPLETED`. |
| D3 | **A** — table `box_office_sales` avec `payment_method` ∈ {`CASH`,`CARD`,`FREE`}, `amount_collected` stocké séparément du prix. |
| D6 | **D (7 sept. 2026)** — e-mail **optionnel** au guichet, `attendees.email = NULL` (jamais fictif), complété a posteriori. **B (obligatoire) annulée.** |
| D9 | **A** — `box_office_sales.idempotency_key` **UNIQUE**, insérée en premier dans la transaction. |
| D11 | **B** — **bloquer** la vente d'un produit non rattaché à une check-in list active. |
| D13 / D14 | **A** — `ORGANIZER` pour vendre **et** réimprimer ; réimpression tracée dans `print_jobs`. |
| D18 | **A** — réutiliser la maquette `attendee-ticket-pdf.blade.php`. |
| D19 | **Tranchée (2026-09-05)** — poste agent = **macOS** pour le développement et le pilote initial (architecture cible compatible Windows ensuite) ; liaison ZD621 = **réseau (IP)** en mode principal, **USB en fallback/secours**. Slice 1 inchangé : PDF + dialogue navigateur, abstraction `TicketRenderer` (aucun ZPL). |
| S4 | **Retenu** — index unique `attendees.public_id` **inclus dans les migrations du slice 1**. |
| S9 | **Clos** — licence commerciale Hi.Events détenue par PICHA. |
| **Tolérance de prix** | **STRICTE** — le prix est celui du palier (`product_prices.price`), **aucun écart accepté** (AC-2 = égalité stricte, pas d'intervalle). |
| D4, D7, D8, D15, D16 | **Reportées après le slice 1.** |

Les sections détaillées ci-dessous restent la référence pour le raisonnement ; seules les lignes du tableau ci-dessus font foi pour le slice 1.

---

## D1 — Figer Somaroho avant le Kiosk

| Option | Détail | Impact |
|---|---|---|
| **A (recommandée)** | Exécuter la procédure de `PICHA_BASELINE_INVENTORY.md §5` : sauvegarde → nettoyage parasites → tests → 10 commits thématiques → tag `digit-staging-somaroho-2026` → brancher le Kiosk depuis ce tag | **[Baseline]** 1 séance dédiée. Rollback possible, historique lisible. |
| B | Ne rien committer, brancher le Kiosk sur `staging` sale | Toute PR Kiosk mélange ~35 fichiers Somaroho + bruit. Revue impossible, rollback impossible. **Déconseillé.** |
| C | Commit unique « WIP Somaroho » fourre-tout puis brancher | Baseline figée mais non revue ; dette de revue reportée. |

**Recommandation : A.** Prérequis absolu au développement Kiosk. Autorisations listées dans `PICHA_BASELINE_INVENTORY.md §6`.

---

## D2 — Moment où l'encaissement est considéré confirmé

| Option | Détail | Impact |
|---|---|---|
| **A (recommandée MVP)** | Encaissement **avant** validation. L'agent encaisse (espèces/TPE), puis crée la vente → Order `COMPLETED` via `CreateAttendeeHandler` (+ garde-fous prix/stock/idempotence de l'orchestrateur) | **[Code]** minimal. Correspond à un guichet réel (on paie, on reçoit le billet). Pas de gestion d'impayés. |
| B | Order `AWAITING_OFFLINE_PAYMENT` d'abord, `MarkOrderAsPaidService` ensuite (« billet remis puis payé ») | **[Code]** élevé : **aucun handler authentifié ne crée un Order `AWAITING_OFFLINE_PAYMENT`** aujourd'hui (`TransitionOrderToOfflinePaymentHandler` exige une session checkout). Il faut un nouveau handler. Permet le suivi d'encours. |
| C | Les deux, selon un choix de l'agent | **[Code]** = B + aiguillage UX. |

**Recommandation : A pour le MVP.** B est un vrai besoin si PICHA veut « réserver puis encaisser plus tard » — à reporter en v2 sauf besoin terrain avéré.

---

## D3 — Traçabilité du moyen de paiement

| Option | Détail | Impact |
|---|---|---|
| **A (recommandée)** | Nouvelle table `box_office_sales` (ou colonnes sur une table de projection Kiosk) : `payment_method` enum(`CASH`,`CARD`,`FREE`,`OTHER`), `amount_collected`, `agent_user_id`, `session_id`, `idempotency_key`, `order_id`, `attendee_id` | **[Schéma]** 1 migration. Sert idempotence (D9) **et** rapports (D16) **et** audit. Ne touche pas `orders`. |
| B | Réutiliser `orders.payment_provider` | enum `PaymentProviders` = `STRIPE`/`OFFLINE` seulement → il faudrait l'étendre (`CASH`,`CARD`…), ce qui impacte le code de paiement partagé. **Risqué.** |
| C | Stocker dans `orders` un champ libre / `point_in_time_data` jsonb | pas de requêtes propres, pas de rapport fiable. |

**Recommandation : A.** La table de projection est de toute façon nécessaire pour l'idempotence (D9) ; y mettre le moyen de paiement est gratuit.

---

## D4 — Un ou plusieurs billets par opération

| Option | Détail | Impact |
|---|---|---|
| A | 1 billet / opération (boucle côté agent) | **[Code]** nul. **[UX]** pénible pour « 4 pass famille ». |
| **B (recommandée)** | L'orchestrateur boucle sur N items → N appels `CreateAttendeeHandler` **dans une transaction englobante**, 1 seule ligne de projection/idempotence pour l'opération | **[Code]** moyen. **[UX]** bon. Attention : `CreateAttendeeHandler` crée **1 Order par attendee** → une « vente » de 4 billets = 4 Orders. À accepter ou à regrouper (nouveau handler multi-items = **[Code]** élevé). |
| C | Nouveau handler `CreateBoxOfficeOrderHandler` : 1 Order, N OrderItems, N Attendees | **[Code]** élevé (réécrit la logique de `CreateAttendeeHandler`). Plus propre comptablement. |

**Recommandation : B pour le MVP** (N appels, transaction englobante, 1 Order par billet — assumé), **C en cible** si le regroupement comptable devient un besoin.

---

## D5 — Acheteur distinct des participants

| Option | Détail | Impact |
|---|---|---|
| **A (recommandée MVP)** | Pas d'acheteur distinct : chaque billet porte le nom/email saisi. Pour un lot, réutiliser les mêmes coordonnées ou saisir chaque participant | **[UX]** simple. `orders.first_name/last_name/email` = ceux du 1er/seul attendee. |
| B | Champ « acheteur » séparé alimentant l'Order, participants alimentant les Attendees | **[Code]** moyen, **[UX]** plus riche. |

**Recommandation : A.**

---

## D6 — Email non fourni — **ROUVERTE (7 sept. 2026), option D**

| Option | Détail | Impact |
|---|---|---|
| A | Rendre `email` nullable | **[Schéma]** `attendees.email` nullable. |
| B | `email` **obligatoire au guichet** | **Annulée (7 sept. 2026).** |
| C | Adresse générée `guichet+<id>@<domaine>` | **écarté** (jamais d'adresse fictive). |
| **D (retenue)** | E-mail **optionnel**. Sans e-mail : `attendees.email = NULL`. Complété a posteriori par ADMIN/ORGANIZER (`PATCH .../attendees/{id}`). Aucun mail tant que l'e-mail est absent. Au moins un identifiant (nom, e-mail ou téléphone). | **[Schéma]** + **[Code]** ciblé. |

**Décision : D.** D6 = B (obligatoire) n'est plus en vigueur.

---

## D7 — Téléphone et organisation

| Option | Détail | Impact |
|---|---|---|
| A | Hors MVP | **[Code]** nul. La recherche de doublon par téléphone (D12) devient impossible. |
| **B (recommandée)** | Via **questions** : l'événement définit une question `PHONE` (type déjà supporté backend) et/ou `SINGLE_LINE_TEXT` « Organisation », `belongs_to = ATTENDEE`. L'orchestrateur Kiosk écrit les `question_answers` via **un nouveau petit service** (aucun n'existe — cf. audit §F) | **[Code]** moyen (service de création de réponses + validation `required`). Réutilise le modèle natif, visible dans les exports. |
| C | Colonnes dédiées sur la table de projection `box_office_sales` | **[Schéma]** simple, mais donnée « à part » du modèle attendee natif, invisible dans les exports Hi.Events. |

**Recommandation : B** si le téléphone est un besoin (recherche de doublon, rappel). Sinon **A**. **Aucune valeur par défaut fictive.**

---

## D8 — Comportement des questions obligatoires

| Option | Détail | Impact |
|---|---|---|
| A | Le Kiosk **ignore** les questions `required` de l'événement (comme `CreateAttendeeHandler` aujourd'hui) | billets guichet incohérents avec les billets web (données manquantes). |
| **B (recommandée)** | Le Kiosk **charge** les questions `required` (`belongs_to` ORDER/PRODUCT/ATTENDEE) de l'événement, les affiche, les valide et les stocke | **[Code]** moyen (dépend de D7-B). Cohérence web/guichet. |
| C | B mais l'agent peut cocher « répondu oralement / N/A » | **[UX]** pragmatique terrain, **[Compta/RGPD]** à cadrer. |

**Recommandation : B** (implique D7-B). Si D7 = A, alors D8 = A par force.

---

## D9 — Mécanisme d'idempotence

| Option | Détail | Impact |
|---|---|---|
| **A (recommandée)** | `box_office_sales.idempotency_key` (string, **UNIQUE**), généré côté client (UUID par opération), inséré **en premier** dans la transaction. Rejeu → violation d'unicité catchée → renvoyer la vente existante | **[Schéma]** 1 colonne + index unique. Conforme « IDs entiers » (la PK reste `id` auto-incrémenté). Pattern déjà utilisé par le module Bracelets (`Idempotency-Key` header). |
| B | Header `Idempotency-Key` + store Redis (comme `ScanIdempotencyService`) | **[Code]** moyen, dépend de Redis (fail-open = risque de doublon en cas de panne Redis — déjà un problème connu du module scan). |
| C | Verrou applicatif (lock nommé) le temps de la requête | ne couvre pas le rejeu après timeout. |

**Recommandation : A** (contrainte DB = source de vérité, indépendante de Redis).

---

## D10 — Produits masqués et fenêtres de vente

| Option | Détail | Impact |
|---|---|---|
| A | Le Kiosk ignore `is_hidden` / dates de vente des produits | l'agent peut vendre un produit non censé être en vente. |
| **B (recommandée)** | Le Kiosk **respecte** `product.is_hidden`, `sale_start_date`/`sale_end_date`, `product.status` — mais offre une **option ADMIN** « vendre un produit masqué » (ex. billets presse/staff) tracée | **[Code]** faible. **[UX]** conforme aux attentes. |

**Recommandation : B.**

---

## D11 — Produit non rattaché à une check-in list (non scannable)

| Option | Détail | Impact |
|---|---|---|
| A | Vendre quand même | billet émis, **rejeté au scan** (`CHECKIN_MATRIX` ligne 4). Colère au portique. |
| **B (recommandée)** | L'orchestrateur **vérifie avant la vente** que `product_id ∈` au moins une check-in list active de l'événement ; sinon **bloque** avec un message clair (« Ce billet ne serait pas scannable — rattachez le produit à une liste d'entrée ») | **[Code]** faible (requête `product_check_in_lists`). **[Sécu/UX]** fort. |
| C | Avertir sans bloquer | risque terrain conservé. |

**Recommandation : B.**

---

## D12 — Recherche et traitement des doublons

| Option | Détail | Impact |
|---|---|---|
| **A (recommandée)** | Avant de créer, l'orchestrateur cherche un attendee existant sur l'événement par email (exact) **et** nom+prénom (ILIKE) ; s'il en trouve → afficher à l'agent (« Jean Dupont a déjà 1 billet — continuer ? / réimprimer ? ») | **[Code]** faible (réutilise `AttendeeRepository::findByEventId`). N'empêche pas, alerte. |
| B | Bloquer tout doublon email/nom | trop rigide (familles, homonymes). |
| C | Aucune détection | doublons silencieux. |

**Recommandation : A.** La détection ne bloque pas ; elle propose « réimprimer l'existant » (lien avec D14).

---

## D13 — Permissions de vente

| Option | Détail | Impact |
|---|---|---|
| **A (recommandée)** | `ORGANIZER` minimum (défaut `BaseAction`), cloisonné par compte/événement. Un « agent de guichet » = un utilisateur `ORGANIZER` de l'organisation | **[Code]** nul. Conforme au modèle (`Role` n'a que SUPERADMIN/ADMIN/ORGANIZER). |
| B | Créer un rôle `BOX_OFFICE_AGENT` | **[Code]** élevé (l'enum `Role` et `IsAuthorizedService` sont structurants). Hors MVP. |

**Recommandation : A.**

---

## D14 — Permissions et motif de réimpression

| Option | Détail | Impact |
|---|---|---|
| **A (recommandée)** | Réimpression = **même attendee**, **jamais** de nouvel Order/Attendee. Autorisée à tout `ORGANIZER`. Chaque réimpression trace `{attendee_id, agent_user_id, reason?, printed_at}` dans `print_jobs` | **[Code]** faible. **[Audit]** bon. `reason` optionnel au MVP. |
| B | Réimpression réservée `ADMIN` | **[UX]** friction (l'agent doit appeler un responsable). |
| C | Réimpression libre, non tracée | pas d'audit anti-fraude. |

**Recommandation : A.**

---

## D15 — Session de caisse

| Option | Détail | Impact |
|---|---|---|
| **A (recommandée MVP)** | Pas de session de caisse formelle. Chaque vente porte `agent_user_id` + `created_at` → un rapport peut agréger par agent/plage horaire a posteriori | **[Code]** nul en plus de D3. |
| B | Table `box_office_cash_sessions` (ouverture/fond de caisse/clôture/écart) | **[Schéma]** + **[UX]** significatifs. Vrai besoin si PICHA fait de la remise d'espèces formelle. |

**Recommandation : A pour le MVP**, B en backlog si gestion d'espèces stricte.

---

## D16 — Rapport de fin de service

| Option | Détail | Impact |
|---|---|---|
| **A (recommandée)** | Écran/export : total vendu, nb billets, ventilation par moyen de paiement (D3) et par produit, sur une plage horaire, filtrable par agent | **[Code]** faible (agrégation SQL sur `box_office_sales`). |
| B | Rien au MVP (les données existent, rapport plus tard) | acceptable si D3 = A. |

**Recommandation : A** (léger, forte valeur terrain).

---

## D17 — Coupure réseau

| Option | Détail | Impact |
|---|---|---|
| **A (recommandée MVP)** | Le Kiosk est **online-only**. En cas de coupure : message clair, bouton « réessayer », l'idempotence (D9) garantit qu'un réessai ne duplique pas | **[Code]** faible. Simple, sûr. |
| B | Mode hors-ligne (file d'attente locale, synchro différée) | **[Code]** très élevé (conflits de stock, QR à générer offline…). Hors MVP. |

**Recommandation : A.** La ZD621 et le poste agent sont sur le réseau du site — exiger une connectivité fiable au guichet.

---

## D18 — Données à imprimer

| Option | Détail | Impact |
|---|---|---|
| **A (recommandée)** | Réutiliser la maquette `attendee-ticket-pdf.blade.php` (Somaroho) : nom, événement, date, type de billet, **QR = `public_id`**, `public_id` en clair, logo. Adapter le format au support choisi (D19) | **[Code]** faible (extraction en service, cf. audit §G). |
| B | Nouvelle maquette dédiée guichet/badge | **[Code]** moyen (design). |

**Recommandation : A**, format à figer avec D19.

---

## D19 — Matériel et connectivité Zebra ZD621 — TRANCHÉE (2026-09-05)

**Décidé par Jo :**

| Sous-décision | Décision | Conséquence technique |
|---|---|---|
| Poste agent | **macOS** pour le développement et le pilote initial. **Architecture cible compatible Windows** ensuite (à ne pas fermer par des choix macOS-spécifiques). | Le chemin slice 1 (navigateur + `window.print()`) est déjà cross-OS — aucun impact. Pour le futur agent d'impression local (si Bluetooth/USB direct est un jour nécessaire), éviter les API strictement macOS (ex. bindings natifs) ; privilégier Node/Python/Go portables. |
| Connexion ZD621 | **Réseau (IP)** en mode principal, **USB en fallback/secours**. | Confirme la voie « le backend peut POSTer du ZPL directement à l'imprimante » (port raw 9100 typique Zebra) comme cible pour le futur driver ZPL de `TicketRenderer` — pas de dépendance à un pilote d'impression OS côté poste agent en fonctionnement normal. L'USB reste le repli si le réseau imprimante tombe (pas de code spécifique au slice 1, prévoir au minimum que le dialogue navigateur reste disponible en secours). |
| Support, résolution, langage, déclenchement | **Non tranchés dans cet échange** — restent ouverts pour le driver ZPL (hors slice 1). | Sans impact sur le slice 1 (PDF + dialogue navigateur, pas de ZPL). |

**Conséquence sur le slice 1 : aucune.** Le slice 1 reste PDF via `AttendeeTicketPdfService` + dialogue d'impression navigateur, sans pilote ZPL — cette décision ne fait que fixer la direction du futur driver réseau de `TicketRenderer`, à construire après le slice 1.

---

## D21 — Invitation (billet offert) sur un produit normalement payant — TRANCHÉE (2026-09-05)

**Contexte :** `CreateBoxOfficeSaleHandler` valide `AC-2` par égalité stricte serveur (`amount ===
product_prices.price`). `payment_method = FREE` (D3) permet de tracer qu'un billet a été remis sans
encaissement — mais ne dispense **pas** de cette égalité : un agent ne peut pas choisir `FREE` pour
vendre à 0 € un billet dont `product_prices.price` est, par exemple, 25,00 €.

**Décidé par Jo :** `FREE` n'est accepté que si le prix serveur du palier est **déjà** 0,00 €
(produit gratuit). Offrir un billet normalement payant (invitation VIP, presse, staff comp) est
**hors périmètre du slice 1** — aucun mécanisme de dérogation de prix n'est introduit ici.

**Conséquence technique :** `CreateBoxOfficeSaleHandler::validatePrice()` rejette
(`BoxOfficePriceMismatchException`) toute combinaison `payment_method = FREE` avec un prix serveur
non nul, au même titre qu'un écart de prix classique — pas de branche de code séparée.

**Reporté en backlog** (post-slice 1, si le besoin terrain se confirme) : un mécanisme explicite
d'invitation/comp sur produit payant, avec sa propre autorisation et sa propre traçabilité
(distincte de `payment_method`), à spécifier séparément.

---

## D20 — Stratégie de baseline Git

Voir D1. Sous-décisions :

| Sous-décision | Recommandation |
|---|---|
| Nom du tag baseline | `digit-staging-somaroho-2026` |
| Point de branchement Kiosk | depuis ce tag, branche `feat/picha-kiosk` |
| `.gitignore` à corriger d'abord | ajouter `frontend/.env.staging.*`, `backups/`, `**/*.bak*` |
| Mention AGPL (S9) | **à trancher séparément** (juridique) avant le commit branding |
| Dumps SQL `backups/*.sql` | sortir du dépôt, stockage privé |

---

## Récapitulatif des recommandations

| # | Recommandation MVP | Nature |
|---|---|---|
| D1 | Figer Somaroho (tag `digit-staging-somaroho-2026`) puis brancher | Baseline |
| D2 | Encaissement **avant** validation → Order `COMPLETED` | Métier |
| D3 | Table `box_office_sales` avec `payment_method` (`CASH/CARD/FREE/OTHER`) | Schéma |
| D4 | Boucle N × `CreateAttendeeHandler` en transaction, 1 Order/billet assumé | Code |
| D5 | Pas d'acheteur distinct | Métier |
| D6 | Email **optionnel** au guichet, `NULL` si absent, pas d'adresse fictive (option D, 7 sept. 2026) | Métier |
| D7 | Téléphone/orga via questions + nouveau service (si besoin), sinon hors MVP | Métier/Code |
| D8 | Respecter les questions `required` (si D7 actif) | Métier |
| D9 | `idempotency_key` UNIQUE sur `box_office_sales`, insérée en premier | Schéma |
| D10 | Respecter `is_hidden`/fenêtres de vente, override ADMIN tracé | Code |
| D11 | **Bloquer** la vente d'un produit non rattaché à une liste active | Code |
| D12 | Détection de doublon non bloquante + proposer réimpression | Code |
| D13 | `ORGANIZER` minimum | Aucune |
| D14 | Réimpression = même attendee, tracée dans `print_jobs`, tout `ORGANIZER` | Code/Schéma |
| D15 | Pas de session de caisse au MVP | — |
| D16 | Rapport de fin de service (agrégat SQL) | Code |
| D17 | Online-only, réessai sûr grâce à l'idempotence | Code |
| D18 | Réutiliser la maquette PDF Somaroho | Code |
| D19 | **Tranchée** — poste agent macOS (cible Windows ensuite), ZD621 en réseau (USB en fallback). MVP slice 1 inchangé : PDF + dialogue navigateur, abstraction `TicketRenderer` | Baseline |
| D20 | Tag + `.gitignore` + sortir les dumps + trancher AGPL | Baseline/Juridique |
