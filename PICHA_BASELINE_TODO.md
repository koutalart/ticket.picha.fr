# PICHA — TODO post-baseline

Dette technique relevée pendant le gel de la baseline Somaroho (commits 0–N sur `staging`).
Non bloquant pour Somaroho. À traiter avant / pendant le Kiosk.

---

## Décisions actées (non rouvertes)

### S9 — Retrait de la mention « Powered by Hi.Events » — RÉSOLU

**Statut : résolu — licence commerciale détenue.**

`PICHA_BASELINE_INVENTORY.md §6` et `PICHA_BOX_OFFICE_DECISIONS_REQUIRED.md` listaient la
question comme décision juridique en attente : le pied de mail (`message.blade.php`, commit
`chore(branding)`) retire la mention « Powered by Hi.Events » requise par l'AGPL §7(b) sauf
licence commerciale.

**Confirmé par Jo (2026-09-04) :** PICHA détient la licence commerciale Hi.Events
(https://hi.events/licensing), qui autorise ce retrait conformément à la clause AGPL §7(b).

Ne pas reposer la question. Si contestée, se référer à la licence commerciale détenue par PICHA.

---

## T1 — Migrations lisant des clés de config renommées

**Contexte :** commit `chore(config): clés de frais par défaut EUR + APP_LOCALE` (a277c953) a renommé
dans `backend/config/app.php` :

| Ancienne clé | Nouvelle clé |
|---|---|
| `app.saas_stripe_application_fee_percent` | `app.default_application_fee_percentage` |
| `app.saas_stripe_application_fee_fixed` | `app.default_application_fee_fixed` |

**Références encore sur les anciennes clés :**

- `backend/database/migrations/2025_01_20_045159_add_configuration_to_accounts.php:17-18`
- `backend/database/migrations/2025_02_16_163546_create_account_configuration.php:24-25`

```php
'percentage' => config('app.saas_stripe_application_fee_percent'),   // -> null
'fixed'      => config('app.saas_stripe_application_fee_fixed') ?? 0, // -> 0
```

**Impact réel :** nul aujourd'hui — ces migrations ont déjà tourné sur staging et prod, elles ne
sont pas rejouées. Risque uniquement si un environnement neuf est monté à partir de zéro : les
`account_configuration` seraient créées avec `percentage = null`.

**Correctif proposé :** remplacer par les nouvelles clés (ou une valeur littérale) dans les deux
migrations, + vérifier qu'aucun autre appel `config('app.saas_stripe_application_fee_*')` ne subsiste.

**Effort :** ~15 min + un test de migration sur base neuve.

---

## T2 — Libellés du billet PDF sans traduction FR

**Contexte :** commit `feat(pdf): billet PDF + QR serveur en pièce jointe du mail attendee` (d249c95f)
a ajouté `backend/resources/views/attendee-ticket-pdf.blade.php`. Les libellés y sont passés dans
`__()` mais aucune entrée correspondante n'existe dans `backend/lang/fr.json` :

- `Date & Time`
- `Organizer`
- `Location`
- `Ticket Type`
- `Attendee`
- `Ticket ID`

**Impact réel :** un billet PDF généré en locale `fr` affiche ces 6 libellés en anglais. Le reste
du PDF (titre événement, nom, e-mail, valeurs) n'est pas concerné.

**Correctif proposé :** ajouter les 6 clés dans `backend/lang/fr.json` (et les autres locales
supportées si besoin), via le workflow `/translations` côté backend. Vérifier le rendu dompdf
avec la police DejaVu Sans (accents OK).

**Effort :** ~10 min.

---

## T3 — Footer collant du widget produit : `position: static` inopérant

**Contexte :** commit `feat(checkout): session_identifier via sessionStorage (cookies tiers bloqués) + footer widget collant` (64fc793b) a ajouté, dans `frontend/src/styles/widget/default.scss` :

```scss
.hi-footer-row.hi-footer-sticky {
  position: static;      // <-- ignore bottom/left/right
  bottom: 0; left: 0; right: 0;
  background-color: ...;
  box-shadow: 0 -2px 12px rgba(0,0,0,0.12);
  z-index: 100;
}
```

**Problème :** `position: static` ignore `bottom/left/right` → le footer n'est pas réellement
collant. Seuls le fond + l'ombre + le padding s'appliquent.

**À vérifier :** la version qui fonctionnait à Somaroho utilisait `position: fixed` (constat de Jo).
Le working tree gelé contient `static` — soit une régression introduite après Somaroho, soit un
correctif volontaire. **Comparer avec le CSS réellement servi par le conteneur frontend déployé**
(`docker compose ... exec frontend` ou inspecter le bundle SSR en prod staging) avant de trancher.

**Correctif probable :** `position: fixed` (ou `sticky` selon le conteneur de scroll), en
revérifiant que le contenu au-dessus reçoit un `padding-bottom` suffisant pour ne pas être masqué
(actuellement `.hi-has-sticky-footer { padding-bottom: 0; }` — suspect si le footer devient fixed).

**Effort :** ~30 min avec test visuel embedded + non-embedded.

---

## T4 — Chaînes FR manquantes : type de question « téléphone »

**Contexte :** commit `feat(questions): type téléphone (frontend)` (95ce90f7) a introduit 3 chaînes
`t\`…\`` sans traduction dans les catalogues Lingui :

- `Phone Number` (`QuestionForm/index.tsx`)
- `A phone number input with basic format validation` (`QuestionForm/index.tsx`)
- `Please enter a valid phone number (digits, spaces, +, -, ( ) only, minimum 8 characters)` (`CheckoutQuestion/index.tsx`)

**Impact réel :** libellés affichés en anglais en locale `fr` (choix du type côté organisateur +
message de validation côté acheteur).

**Correctif :** workflow `/translations` frontend — `yarn messages:extract`, traduire dans
`frontend/src/locales/fr/*`, `yarn messages:compile`. Couvrir aussi les autres locales supportées.

**Effort :** ~10 min.

---

## T5 — `hero.jpeg` du thème festival non optimisé

**Contexte :** commit `feat(festival): thème éditorial festival + route /festival/:slug` ajoute
`frontend/public/assets/festival/somaroho/hero.jpeg` — **562 Ko**, servi tel quel (pas de variante
responsive, pas de format moderne).

**Impact réel :** LCP dégradé sur `/festival/:eventSlug`, surtout mobile. Pas bloquant.

**Correctif proposé :** compresser (viser < 150 Ko), générer un WebP/AVIF avec fallback, ou passer
par un pipeline d'images existant s'il y en a un dans le projet.

**Effort :** ~15 min.

---

## T6 — Thème festival : clients API dupliqués + données événement en dur

**Contexte :** même commit, `frontend/src/themes/festival/services/{eventApi,pricingApi,ticketApi}.ts`
et `frontend/src/themes/festival/events/somaroho.ts`.

**Problème 1 — doublons probables :** `eventApi.ts` / `pricingApi.ts` / `ticketApi.ts` sont des
clients API dédiés au thème festival ; à comparer avec `frontend/src/api/*.client.ts` existants
pour voir s'ils réimplémentent des appels déjà couverts (risque de divergence de comportement /
double maintenance).

**Problème 2 — données en dur :** `events/somaroho.ts` encode les données d'un événement précis
dans le code source (même dette que `CUSTOM_DOMAINS` dans `server.js`, commit 10) — pas
généralisable à un autre événement sans nouveau commit.

**Correctif proposé :** évaluer si `eventApi`/`pricingApi`/`ticketApi` peuvent être remplacés par
les clients React Query existants ; sortir les données Somaroho vers une config/CMS si le thème
festival doit resservir pour d'autres événements.

**Effort :** à chiffrer — dépend de la décision produit sur la réutilisabilité du thème festival.

---

## T7 — `check-env-secrets.sh` ne peut jamais échouer

**Contexte :** commit `infra(frontend): garde-fou check-env-secrets au build` (bab837b6) ajoute
`frontend/check-env-secrets.sh`, exécuté dans `Dockerfile.ssr` juste après `COPY . .` :

```sh
if grep -E "^VITE_.*=(sk_live_|sk_test_|whsec_)" .env.staging 2>/dev/null; then
  echo "ERREUR: ..."; exit 1
fi
echo "OK: ..."
```

**Problème :** `frontend/.dockerignore` (`*.env*`) et le `.dockerignore` racine
(`frontend/.env*`) excluent `.env.staging` du contexte de build Docker → le fichier n'existe
jamais dans l'image au moment du `RUN` → `grep` échoue silencieusement (`2>/dev/null`) →
la branche `if` n'est jamais vraie → le script affiche toujours « OK », quel que soit le contenu
réel de `frontend/.env.staging` sur la machine qui build.

De plus, `docker-compose.staging.yml:88` (`env_file: frontend/.env.staging`) est une directive
**runtime** du service `frontend` (au `docker compose up`), pas une entrée du contexte de build —
elle ne fait pas apparaître le fichier pendant `docker build`.

**À faire :**
1. Documenter comment le build SSR staging reçoit réellement ses `VITE_*` (build args passés à
   `docker build`/`docker compose build` ? fichier `.env` copié manuellement avant le build ?
   variables d'environnement de l'hôte lues par Vite via `process.env` ?).
2. Déplacer le contrôle secrets au point d'entrée réel identifié en (1) — build arg, script de
   déploiement, ou étape CI — plutôt que sur un fichier absent du contexte Docker.
3. Vérifier avec un test positif volontaire (ex. `sk_test_` factice dans le fichier réellement lu
   au build) que le nouveau point de contrôle échoue bien.

**Élément de réponse trouvé au commit `feat(ssr)` (frontend/server.js) :** les `VITE_*` ne sont
**pas** bakées au build — `getViteEnvironmentVariables()` les lit depuis `process.env` **au
runtime**, à chaque requête SSR, et les injecte dans `window.hievents` côté client. Elles arrivent
dans le conteneur via `env_file: frontend/.env.staging` (runtime, `docker-compose.staging.yml:88`),
pas via le contexte de build. Conséquence : `check-env-secrets.sh` vérifie une étape (build) qui
n'est structurellement pas celle par laquelle les `VITE_*` réels transitent — le vrai point de
contrôle est le contenu de `frontend/.env.staging` au démarrage du conteneur `frontend`, pas au
`docker build`. Reste à trancher en (2) : contrôle à exécuter au démarrage du conteneur (entrypoint)
ou en pré-déploiement, pas dans le Dockerfile.

**Effort :** ~30–45 min (déplacement du contrôle vers l'entrypoint runtime ou le pipeline de
déploiement).

---

## T8 — `generate-domain-objects` produit des DO pour des tables hors domaine métier

**Contexte :** `backend/app/Services/Infrastructure/DomainObjectGenerator/ClassGenerator.php:42-46`
n'ignore que 3 tables :

```php
private array $ignoredTables = [
    'migrations',
    'job_batches',
    'failed_jobs',
];
```

`run()` (ligne 68) boucle sur **toutes** les tables retournées par `$schemaManager->listTables()`
et génère un DomainObject pour chacune, sauf ces 3.

**Preuve :** comparaison entre les fichiers `backend/app/DomainObjects/*.php` trackés dans ce repo
et ceux présents dans un conteneur où le générateur a déjà tourné (staging) — 10 fichiers générés
qui n'existent **pas** dans le dépôt :

- `CacheDomainObject.php`, `CacheLockDomainObject.php`, `JobDomainObject.php` — tables techniques
  Laravel (`cache`, `cache_locks`, `jobs`), pas des concepts métier.
- `DigitBraceletDomainObject.php`, `DigitEventSecurityKeyDomainObject.php`,
  `DigitScanDeviceDomainObject.php`, `DigitScanDeviceActivationCodeDomainObject.php`,
  `DigitScanDeviceAuditLogDomainObject.php`, `DigitScanDeviceCheckInListDomainObject.php` — tables
  du module `modules/digit` (créées par les migrations `2026_07_05_*` à `2026_07_11_*`).
- `ScanLogDomainObject.php` — table `scan_logs` (migration `2026_07_18_000000`).

**Impact réel :** aucun aujourd'hui (ces fichiers ne sont pas commités, donc pas utilisés). Le
risque est pour la prochaine personne qui lance `generate-domain-objects` après une migration :
elle se retrouvera avec ces 10 fichiers en `git status`, sans savoir s'ils doivent être commités
ou non — pollution du diff, confusion sur ce qui est « domaine ».

**Constat annexe :** reproduire la génération dans le conteneur `docker/development` fraîchement
démarré échoue actuellement avec `unlink(): Permission denied` sur les fichiers existants —
`app/DomainObjects/Generated/*.php` appartiennent à `ubuntu:docker` (bind-mount hôte) alors que le
process PHP tourne en `www-data` (uid 82), qui n'a pas les droits d'écriture. Distinct de T8 mais
bloquant pour qui voudrait régénérer localement : à corriger (utilisateur du conteneur aligné sur
l'hôte, ou permissifs sur `backend/app/DomainObjects/`).

**Correctif proposé :** étendre `$ignoredTables` (ou passer à une liste blanche de tables
métier) pour exclure `cache`, `cache_locks`, `jobs`, `sessions`, `password_reset_tokens`,
`personal_access_tokens`, et les tables `digit_*`/`scan_logs` si elles ne doivent pas avoir de
DomainObject généré (à trancher : le module `digit` a peut-être besoin des siens, auquel cas les
committer plutôt que les ignorer).

**Effort :** ~20 min pour la liste d'exclusion + décision sur le sort des DO `Digit*`/`ScanLog`.

---

## T9 — Baseline `tsc --noEmit` : 96 erreurs, 94 héritées de l'amont + 2 imports inutilisés

**Mesure (2026-09-04, `docker/development`, conteneur `frontend`, `npx tsc --noEmit`) : 96 erreurs
au total.**

**2 erreurs `TS6133` (déclaré mais jamais lu) localisées dans `themes/festival`, seul dossier
PICHA-spécifique touché :**
- `frontend/src/themes/festival/components/TicketCard/index.tsx:2` — import `classNames` inutilisé.
- `frontend/src/themes/festival/services/ticketApi.ts:12` — paramètre `day` inutilisé.

**94 erreurs réparties sur 54 fichiers du cœur Hi.Events amont**, aucun sous `themes/festival`,
`modules/digit` ni tout autre chemin PICHA-spécifique — ex. `src/stores/app.store.ts` (module
`zustand` introuvable), `src/components/modals/ManageOrderModal/index.tsx` (7 erreurs),
`src/components/routes/event/GettingStarted/ConfettiAnimaiton/index.tsx` (7 erreurs),
`src/components/routes/welcome/index.tsx` (6 erreurs), etc. Cohérent avec des erreurs préexistantes
dans l'amont plutôt qu'introduites par les commits Somaroho/PICHA.

**Impact réel :** `tsc --noEmit` n'est pas vert aujourd'hui et ne l'était probablement pas avant la
baseline PICHA. À utiliser comme référence : toute PR Kiosk ne doit **pas augmenter** ce nombre
(objectif : rester à 96, viser 0 en réduisant au fil de l'eau, en commençant par les 2 propres à
PICHA qui sont triviales à corriger).

**Correctif immédiat possible :** les 2 imports/paramètres inutilisés de `themes/festival`
(5 min). Les 94 autres : hors périmètre Kiosk, à traiter séparément (montée de version amont ou
nettoyage dédié).

**Effort :** 5 min (les 2 PICHA) + non chiffré pour les 94 héritées (hors périmètre).

---

## T10 — Extension `gd` manquante dans `Dockerfile.dev` — CORRIGÉ

**Contexte :** `backend/Dockerfile.dev` n'installait que `intl imagick` (`install-php-extensions
intl imagick`). Le rendu du billet PDF (`AttendeeTicketMail::generateTicketPdf`,
`attendee-ticket-pdf.blade.php`) dépend de `dompdf`, qui a besoin de `gd` pour le traitement des
images embarquées (logo, QR).

**Corrigé** par le commit `2809a04b infra(dev): extension gd dans Dockerfile.dev (PDF billet)` :

```diff
-RUN install-php-extensions intl imagick
+RUN install-php-extensions intl imagick gd
```

**Vérifié** dans le conteneur `docker/development` reconstruit le 2026-09-04 : `php -m | grep gd`
→ `gd` présent.

**Lien avec le constat terrain ci-dessous** : ce correctif répond directement au symptôme observé
par Jo (500 après commit sur génération du PDF billet, faute de `gd`).

---

## T11 — Billet PDF ≈ 1,2 Mo, à optimiser

**Mesure rapportée par Jo (poste de terrain).** Non re-mesurée dans cette session (nécessiterait un
événement de test avec logo uploadé, hors périmètre « aucun code applicatif » de cette tâche) —
mais cause probable confirmée par lecture du code :

- `AttendeeTicketMail::generateTicketPdf()` (`backend/app/Mail/Attendee/AttendeeTicketMail.php:142-144`)
  récupère l'URL du logo événement (`ImageType::TICKET_LOGO`) et le passe tel quel à la vue :
  `$logoUrl = $logoImage ? Url::getCdnUrl($logoImage->getPath()) : null;` — **aucun
  redimensionnement**, dompdf télécharge et embarque l'image dans sa résolution d'upload
  d'origine.
- `attendee-ticket-pdf.blade.php:66` : `<img src="{{ $logoUrl }}" class="logo" />` — le CSS
  (`max-width: 120px; max-height: 60px`) ne contraint que l'**affichage**, pas les octets
  embarqués dans le PDF.
- Le QR (`qrCodeBase64`, ligne 146-148 du Mail) est généré en interne à taille fixe
  (`size(300)`), donc pas la source principale du poids.

**Correctif proposé :** contraindre/redimensionner le logo côté serveur avant de le passer à la
vue (ex. réutiliser un pipeline d'image existant si disponible, ou limiter à une résolution
raisonnable ~240×120 avant `base64_encode`/embarquement), plutôt que de laisser dompdf télécharger
l'original.

**Effort :** ~30 min (redimensionnement + test avec un logo réellement volumineux).

---

## T12 — 10 branches dependabot orphelines, jamais mergées

**Constat (2026-09-04) :** 10 branches `origin/dependabot/*`, toutes **1 commit en avance / 23
commits en retard** sur `origin/staging`, datées du **2026-07-04** (2 mois, avant toute la
stabilisation Somaroho) — aucune n'a été mergée ni fermée :

Backend (composer) :
- `dependabot/composer/backend/barryvdh/laravel-dompdf-3.1.2`
- `dependabot/composer/backend/ezyang/htmlpurifier-4.19.0`
- `dependabot/composer/backend/league/flysystem-aws-s3-v3-3.35.1`
- `dependabot/composer/backend/spatie/laravel-data-4.23.0`
- `dependabot/composer/backend/spatie/laravel-ignition-2.12.0`

Frontend (npm/yarn) :
- `dependabot/npm_and_yarn/frontend/react-pdf/renderer-4.5.1`
- `dependabot/npm_and_yarn/frontend/react-qr-code-2.2.0`
- `dependabot/npm_and_yarn/frontend/remix-run/node-2.17.5`
- `dependabot/npm_and_yarn/frontend/sass-1.101.0`
- `dependabot/npm_and_yarn/frontend/tiptap/extension-text-align-2.27.2`

(Le remote `upstream` en a 2 de plus — `laravel/vapor-core`, `nette/php-generator` — hors
périmètre PICHA, ce sont celles du dépôt Hi.Events amont.)

**Impact réel :** aucune régression de sécurité connue (ce sont des montées de version mineures),
mais 23 commits de retard = risque de conflit croissant si elles sont mergées tardivement, et bruit
dans la liste de branches.

**Correctif proposé :** trier par lot — rebase + test rapide pour chaque, merger celles qui passent
sans conflit (majorité probable pour de simples bumps de patch/minor), fermer/relancer dependabot
pour les autres. Prioriser `barryvdh/laravel-dompdf` et `react-pdf/renderer` vu leur lien direct
avec le Kiosk (génération PDF billet).

**Effort :** ~1h30 pour les 10 (rebase + `composer install`/`yarn install` + test unitaire rapide
par branche), en dehors du développement Kiosk lui-même.

---

## Constats complémentaires

### Correction de l'audit v2 — `attendees.notes` existe bien

`PICHA_BOX_OFFICE_AUDIT_v2.md §4.6` affirme : *« La table `attendees` **n'a pas de colonne
`notes`** »*, en réfutation d'une affirmation de l'audit v1.

**Ce constat est erroné.** La migration
`backend/database/migrations/2024_12_09_234323_add_notes_to_attendees_table.php` ajoute bien
`attendees.notes` (`text`, nullable), de façon idempotente (`if (Schema::hasColumn(...)) return;`).
**Vérifié** sur la base migrée du conteneur `docker/development` (2026-09-04) :
`Schema::hasColumn('attendees', 'notes')` → `true`.

Le reste du constat §4.6 (recherche plein texte via `ILIKE` sur `first_name`/`last_name`/
`public_id`/`email`, `filter_fields` limités à `status`/`product_id`/`product_price_id`) n'est pas
remis en cause ici — seule l'affirmation sur l'absence de la colonne `notes` est fausse.

### Constat terrain — trois doublons créés en réessayant après un 500 (`gd`) — preuve de S3

Rapporté par Jo : sur `docker/development` (Mac de Jo, `QUEUE_CONNECTION=sync`), avant le correctif
T10, la génération du billet PDF échouait avec une 500 (extension `gd` manquante). L'agent guichet
a réessayé l'opération plusieurs fois en pensant que la vente n'avait pas abouti — **trois
participants en doublon** ont été créés pour la même personne. Fait observé et confirmé : les
Order/Attendee ont bien été persistés, le mail « commande confirmée » est parti, seul le mail
billet a échoué (gd), et l'API a renvoyé 500 malgré la persistance réussie.

**Mécanisme reproduit et vérifié (2026-09-05, non supposé)** : la cause n'est **pas** que
`CreateAttendeeHandler::handle()` committe puis déclenche l'envoi du mail de façon synchrone dans
le même flux — c'est plus précis que ça. `app/Mail/BaseMail.php:16-19` :

```php
public function __construct()
{
    $this->afterCommit();
}
```

`BaseMail` (dont hérite `AttendeeTicketMail`, `OrderSummary`, etc.) est un `Mailable implements
ShouldQueue`, et son constructeur appelle `$this->afterCommit()` — ce qui positionne la propriété
`Queueable::$afterCommit = true` sur **chaque instance de mail**, indépendamment de la queue
utilisée pour le *Job* qui l'envoie. Résultat, avec `QUEUE_CONNECTION=sync` :

- `event(new OrderStatusChangedEvent(...))` (dans la transaction de `CreateAttendeeHandler::handle()`)
  déclenche `SendOrderDetailsEmailListener` → `dispatch(new SendOrderDetailsEmailJob($order))` — ce
  *Job* lui-même n'a pas `afterCommit=true`, donc il s'exécute **immédiatement**, toujours dans la
  transaction ouverte.
- Mais `SendOrderDetailsEmailJob::handle()` appelle `$this->mailer->send($mail)` où `$mail`
  (`OrderSummary`, puis `AttendeeTicketMail`) a `afterCommit=true` — Laravel ne rend/envoie donc
  **rien** à cet instant : il enregistre un callback différé (`db.transactions`-
  `>addCallback(...)`, `SyncQueue::push()`), qui n'exécutera le rendu réel du mail (et donc la
  génération PDF) **qu'après le COMMIT** de la transaction englobante.
- La transaction de `CreateAttendeeHandler::handle()` se termine donc sans exception, **committe**
  (Order + Attendee + OrderItem + `quantity_sold` incrémenté, tous durablement écrits) — puis,
  juste après, les callbacks différés s'exécutent : le mail « commande confirmée » (`OrderSummary`,
  pas de PDF) réussit, puis le rendu du billet (`AttendeeTicketMail::generateTicketPdf()`) plante
  (gd manquant). L'exception remonte alors jusqu'à l'appelant HTTP — **après** le commit — d'où la
  500 malgré une vente déjà actée en base.

**Reproduit empiriquement** (script ad hoc, `AttendeeTicketPdfService` remplacé par un double qui
lève une exception, `CreateAttendeeHandler::handle()` appelé sans transaction englobante
supplémentaire) : `handle()` lève bien l'exception, mais `attendees=1`, `orders=1`,
`quantity_sold=1` et `DB::transactionLevel()=0` **après** l'exception — la ligne est là, committée,
malgré l'échec remonté à l'appelant.

**Preuve concrète de S3** (`PICHA_BOX_OFFICE_SECURITY_FINDINGS.md` — aucune idempotence sur la
création manuelle) : sans clé d'idempotence, un agent qui réessaie après une erreur perçue comme
« la vente a échoué » crée autant de nouveaux Orders/Attendees qu'il y a de tentatives, chacun
avec sa propre place détectée comme vendue (`quantity_sold` incrémenté à chaque fois). Le correctif
`gd` (T10) supprime le déclencheur immédiat de ce cas précis, mais **ne corrige pas S3** : toute
autre cause d'échec après commit (timeout réseau, mail indisponible, etc.) reproduirait le même
doublon.

**Confirme aussi le besoin de découpler vente et rendu PDF** (cf. `FIRST_SLICE` D19/T11) : tant que
la génération du PDF est synchrone dans le flux de vente (même indirectement, via l'envoi de mail
déclenché par l'event), une panne de rendu (police manquante, image distante indisponible, etc.)
reste capable de faire échouer — ou de faire percevoir comme échouée — une vente déjà actée en
base. Le slice 1 du Kiosk répond en partie à ceci en générant le PDF à la demande
(`GET .../ticket.pdf`) plutôt que dans le flux de vente, mais le flux natif (mail de confirmation)
reste exposé.

---

## T13 — Aucun framework de test frontend configuré

**Contexte :** le test 41 de `PICHA_BOX_OFFICE_FIRST_SLICE.md §5.3` (« la page n'affiche pas les
produits non scannables ; le prix est en lecture seule ; le bouton se désactive pendant la
mutation ; `idempotency_key` régénéré à chaque ouverture du formulaire ») nécessite un test de
composant React. **Aucun outillage de test n'existe côté frontend** : pas de Vitest, pas de Jest,
pas de React Testing Library dans `frontend/package.json`, aucun fichier `*.test.tsx`/`*.test.ts`
dans le dépôt.

**Impact réel :** le test 41 ne peut pas être écrit sans d'abord choisir et installer un
framework — un vrai choix d'outillage (config, devDependencies), pas un TDD sur de l'existant.
Reporté hors du slice 1 côté tests ; la page Box Office elle-même n'est pas bloquée, seule sa
couverture par un test de composant l'est.

**Correctif proposé :** décider du framework (Vitest + React Testing Library est le choix standard
pour un projet Vite comme celui-ci) avec Jo, puis écrire le test 41 une fois l'outillage en place.

**Effort :** ~30 min d'installation/config + le temps d'écrire le test 41 lui-même.

---

## T14 — `OrderSummary` plantait si l'événement n'a pas de date de début — CORRIGÉ

**Découvert en reproduisant le constat terrain ci-dessus (2026-09-05).** En appelant
`CreateAttendeeHandler::handle()` sur un événement sans `start_date` (champ nullable en base), le
mail `OrderSummary` (« commande confirmée ») levait :

```
Illuminate\View\ViewException: HiEvents\Helper\DateHelper::convertFromUTC(): Argument #1
($eventDate) must be of type string, null given ... (View: resources/views/emails/orders/summary.blade.php)
```

**Correction au premier écrit de cette entrée (2026-09-05) :** attribué à `getEndDate()` — faux.
Vérifié par lecture du fichier : `summary.blade.php` n'appelle `getEndDate()` **nulle part**. Les
deux plantages venaient de `$event->getStartDate()` (lignes 17 et 45 après correctif), utilisé
sans garde par `DateHelper::convertFromUTC(string $eventDate, ...)` — paramètre non nullable.
`start_date` est nullable en base (`information_schema.columns`, vérifié) mais normalement toujours
renseigné par le flux de création d'événement standard ; le cas ne se manifeste que si un événement
est créé par un chemin qui l'omet (comme la fixture de test minimaliste utilisée pour reproduire le
constat terrain).

Comme `OrderSummary` hérite de `BaseMail` (`ShouldQueue` + `afterCommit()`, voir constat ci-dessus),
ce plantage se produisait **après le commit** de la vente — même symptôme que le plantage `gd` :
vente actée, mail cassé, 500 renvoyé à l'appelant.

**Impact réel :** tout événement sans `start_date` renseignée faisait échouer le mail de
confirmation (et la requête HTTP qui l'a déclenché) pour **toute** création d'attendee (manuelle,
guichet, achat normal) — pas seulement au guichet.

**Corrigé** (commit séparé, cherry-pickable vers staging) : garde `@if($event->getStartDate())`
autour des deux blocs de `summary.blade.php` qui en dépendent, avec une phrase de repli sans date
pour le premier paragraphe (traduite FR). Testé : `tests/Unit/Mail/Order/OrderSummaryTest.php`
(rendu sans erreur sans `start_date`, et rendu inchangé — date/heure toujours affichées — avec).

**Effort :** ~10 min.

---

## T15 — Suite Feature : fuite de locale entre tests (`es` au lieu de `en`)

**Découvert en lançant `--testsuite=Feature` en entier (2026-09-05), sans lien avec le Kiosk.**
`EmailTemplateTokenTest::test_can_get_order_confirmation_tokens` échoue **uniquement** en suite
complète (passe seul) : les descriptions de tokens reviennent en espagnol (« El nombre de la
persona que realizó el pedido ») au lieu d'anglais. Reproduit sans aucun test Box Office dans la
sélection — un autre test de la suite positionne la locale app sur `es` (probablement via une
requête `locale=es`) sans la restaurer, et PHPUnit exécute tous les tests Feature dans le même
processus PHP.

**Impact réel :** aucun sur le Kiosk. Fragilise la suite Feature (dépendance à l'ordre
d'exécution) — masque potentiellement d'autres bugs de locale ailleurs.

**Correctif proposé :** identifier le test fautif (`grep -rn "locale.*=.*es\b" backend/tests/Feature`)
et restaurer `App::setLocale('en')` dans son `tearDown()`, ou passer par `Illuminate\Testing`
`withLocale`/isoler ces tests en base séparée.

**Effort :** ~20 min (localisation + correctif).

---

## T16 — `GET /events/{id}/products` casse systématiquement (500) — BLOQUANT pour le Kiosk, hérité de Somaroho

**Découvert en préparant la capture d'écran de la page Box Office (2026-09-06).** L'endpoint liste
des produits (utilisé par la page de gestion « Tickets & Products », **et** par la nouvelle page
Box Office) renvoie une 500 sur **tout** appel, y compris avec les paramètres de pagination par
défaut — pas un cas limite :

```
TypeError: HiEvents\Services\Domain\Product\ProductFilterService::{closure...}():
Argument #1 ($category) must be of type HiEvents\DomainObjects\ProductCategoryDomainObject,
HiEvents\DomainObjects\ProductDomainObject given
```

**Cause identifiée avec précision** (lecture de code, pas supposition) :
`GetProductsHandler::handle()` (`app/Services/Application/Handlers/Product/GetProductsHandler.php:21-31`)
appelle `ProductRepository::findByEventId()` qui renvoie une pagination de
**`ProductDomainObject`** bruts (liste plate, `app/Repository/Eloquent/ProductRepository.php:30-53`
— c'est correct pour ce que fait cette méthode). Il transmet ensuite directement cette collection
à `ProductFilterService::filter()`, dont la signature et le corps (`filter():44-59`) exigent
explicitement une `Collection<ProductCategoryDomainObject>` (des catégories contenant des produits
imbriqués via `getProducts()`) — pas des produits à plat. Le `flatMap` interne appelle
`$category->getProducts()` sur ce qui est en réalité déjà un `ProductDomainObject` → plantage.

**Vérifié** : reproduit avec un événement neuf, avec et sans catégorie assignée aux produits — le
plantage est systématique, pas lié à l'absence de catégorie.

**Confirmé hérité de Somaroho, pas introduit par le Kiosk (2026-09-06)** : reproduit à l'identique
sur le tag `digit-staging-somaroho-2026`, dans un worktree Git temporaire séparé, avec sa propre
base Postgres neuve (`baseline_check`, supprimée après coup) et ses propres dépendances composer —
aucun commit Kiosk présent. Même compte/organisateur/événement/produit minimal créés à la main,
même appel `GetProductsHandler::handle()` → même `TypeError` exact. Confirmé aussi par diff :
`git diff digit-staging-somaroho-2026 feat/picha-kiosk -- <les fichiers en cause>` ne montre
**aucune** différence sur `GetProductsHandler.php`, `ProductFilterService.php`,
`ProductRepository::findByEventId()` ni les Domain Objects concernés — seule modification de ce
dernier fichier : l'ajout, à la fin, de la méthode `hasActiveCheckInList()` du Kiosk (sans rapport,
n'affecte pas `findByEventId()`). Worktree, conteneur et base de test supprimés après vérification.

**Impact réel :** la page de gestion **native** « Tickets & Products » (`/manage/event/:id/products`)
est cassée pour **tout** événement, y compris sur la baseline Somaroho figée — à vérifier en
priorité auprès de Jo, car c'est une page cœur de métier, sans rapport avec le Kiosk et présente
avant le début de ce travail.

**⚠️ Ce n'est PAS qu'un problème de capture d'écran de démo — le slice 1 du Kiosk est
fonctionnellement bloqué en pratique, pas seulement indisponible pour vérification visuelle**
(vérifié le 2026-09-06, à la demande de Jo) : la page Box Office frontend
(`frontend/src/components/routes/event/BoxOffice/index.tsx`) liste les produits vendables via
`useGetProducts` (`frontend/src/queries/useGetProducts.ts`) → `productClient.all()` → **le même**
`GET /events/{id}/products` → `GetProductsAction` → `GetProductsHandler` cassé. Tant que T16 n'est
pas corrigé, la grille de produits de la page Box Office reste indéfiniment sur son état de
chargement (squelette) — **aucun agent ne peut sélectionner un billet à vendre, sur aucun
événement.** Le code du slice 1 (backend endpoints, handler, page frontend) est complet et testé
de bout en bout côté backend (tests HTTP réels), mais **inutilisable en pratique tant que T16
n'est pas corrigé** — T16 doit être traité avant toute mise en service du Kiosk, pas seulement «
quand on aura le temps ».

**Correctif proposé (à valider avec Jo avant d'agir, hors périmètre de cette session)** : soit
`GetProductsHandler` doit appeler une méthode de filtrage adaptée aux listes plates (ou sauter le
filtrage catégorie), soit `findByEventId` doit être adapté pour renvoyer des catégories — à trancher
selon l'usage réel attendu de ce endpoint (affiche-t-il les produits groupés par catégorie côté
front, ou une liste plate ?). Ne pas corriger à l'aveugle : `ProductFilterService::filter()` est
probablement aussi appelé correctement ailleurs (page publique de l'événement) — un correctif mal
ciblé pourrait casser l'autre appelant.

**Effort :** ~30 min de correctif une fois l'usage attendu confirmé, + tests de non-régression sur
les deux appelants de `ProductFilterService::filter()`.

---

## Note — `CreateAttendeeHandler` : résolution du générateur par service locator

`app/Services/Application/Handlers/Attendee/CreateAttendeeHandler.php:233` résout
`AttendeePublicIdGenerator` via `app(AttendeePublicIdGenerator::class)` **au point d'usage**,
plutôt que par injection dans le constructeur du handler.

**Choix délibéré**, pas un oubli : la consigne de la session Kiosk imposait « une seule ligne
modifiée » dans ce fichier (préserver au maximum le chemin natif, non retouché depuis Somaroho).
Ajouter une dépendance au constructeur aurait nécessité une deuxième ligne (le paramètre) plus la
mise à jour de tout appelant construisant `CreateAttendeeHandler` explicitement — hors du budget de
la modification autorisée. Le service locator garde le diff à un import + une ligne.

**Dette assumée :** ce pattern s'écarte de l'injection de dépendances classique utilisée partout
ailleurs dans le handler (tous les autres collaborateurs sont injectés au constructeur). À corriger
si `CreateAttendeeHandler` est un jour retouché plus largement — remplacer par une injection
normale à cette occasion plutôt que d'ajouter un deuxième service locator à côté.
