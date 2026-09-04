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
