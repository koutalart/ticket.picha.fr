# PICHA Kiosk v2 — Audit de conception & décisions requises

Date : 6 septembre 2026 · **Aucun code applicatif écrit.** Ce document est un audit de conception.
Modèle : `PICHA_BOX_OFFICE_FIRST_SLICE.md` + `PICHA_BOX_OFFICE_DECISIONS_REQUIRED.md`.
Les lignes « DÉCISION REQUISE » attendent une validation explicite de Jo / PICHA.

**Décidé par Jo (6 sept. 2026) :** **D23** (compte opérateur de guichet) — voir le bloc
« ✅ Décision actée » de la section D23 et le **parcours vertical v2.1 définitif** en fin de document.

Contexte : le **slice 1 du Kiosk** (branche `feat/picha-kiosk`, commit `2f8a47b3`) est fonctionnel et
testé de bout en bout — vente 1 billet, prix serveur strict, idempotence, PDF, réimpression,
compat check-in natif. Jo veut le faire évoluer vers une **v2 inspirée de Weezevent**, sur un
sous-domaine dédié `kiosk.picha.fr`.

---

## Méthode

Chaque point ci-dessous a été instruit par **lecture du code réel** avant conclusion, comme l'audit
initial. Fichiers lus :

- `frontend/server.js` (SSR, `CUSTOM_DOMAINS`)
- `frontend/src/api/client.ts`, `frontend/src/utilites/apiClient.ts`, `frontend/src/entry.{server,client}.tsx`
- `frontend/src/router.tsx`, `frontend/src/components/layouts/Event/index.tsx`
- `frontend/src/components/routes/event/BoxOffice/index.tsx`, `.../event/orders.tsx`
- `backend/app/Http/Actions/Auth/BaseAuthAction.php`, `backend/config/{cors,session,jwt}.php`
- `vendor/php-open-source-saver/jwt-auth/src/Providers/LaravelServiceProvider.php` (chaîne de parsers JWT)
- `backend/app/Services/Infrastructure/Authorization/IsAuthorizedService.php`, `backend/app/DomainObjects/Enums/Role.php`
- `backend/app/Http/Actions/BaseAction.php`, `backend/app/Http/Middleware/SetAccountContext.php`, `backend/app/Models/User.php`
- `backend/app/Services/Domain/Auth/{LoginService,AuthUserService}.php`, `backend/app/Services/Application/Handlers/Auth/{LoginHandler,AcceptInvitationHandler}.php`
- `backend/app/Services/Application/Handlers/User/CreateUserHandler.php`, `backend/app/Http/Actions/Users/CreateUserAction.php` + `CreateUserRequest.php`, `backend/app/Services/Domain/Account/AccountUserAssociationService.php`
- `backend/database/migrations/schema.sql` (`account_users`), `backend/database/migrations/2024_08_08_032637_create_check_in_lists_tables.php`
- `backend/database/migrations/2026_09_05_000000_create_box_office_sales_table.php`, `..._000001_create_print_jobs_table.php`
- `backend/app/Services/Application/Handlers/BoxOffice/CreateBoxOfficeSaleHandler.php` + DTO + Action + Request
- `backend/app/Services/Application/Handlers/Attendee/CreateAttendeeHandler.php`
- `backend/modules/digit/src/Scan/Http/Middleware/AuthenticateScanDevice.php`, `backend/modules/digit/routes/scan.php`, `.../Console/Commands/DigitScanDeviceCreateCommand.php`, `.../Devices/Domain/Services/DeviceActivationCodeService.php`, `.../database/migrations/2026_07_05_000000_*` + `2026_07_11_130000_*`, `.../Providers/DigitScanServiceProvider.php`
- Actions gâtées de l'existant vérifiées : `EditEventSettingsAction`, `UpdateEventAction`, `ExportOrdersAction`, `GetEventsAction`, `GetUsersAction`, `CreateUserAction`
- `backend/routes/api.php` (groupes `auth:api` / `/public`, routes orders & check-in)
- `backend/app/DomainObjects/OrderDomainObject.php`, `backend/app/Repository/Eloquent/OrderRepository.php`
- `backend/app/Services/Domain/Event/EventStatsFetchService.php`, `backend/app/Listeners/Event/UpdateEventStatsListener.php`

Légende impact : **[Infra]** déploiement/DNS/proxy · **[Code]** volume de dev · **[Schéma]** migration ·
**[UX]** parcours opérateur · **[Sécu]** · **[Compta]** rapports/audit.

Catégories (par point) :
- **CONFIRMÉ** — établi par lecture du code, pas de choix à faire.
- **NON DÉTERMINÉ** — dépend d'infos hors dépôt (infra PICHA, besoin terrain) à obtenir avant de trancher.
- **DÉCISION REQUISE** — choix métier/technique qui engage la suite, à valider par Jo.

---

## 0. Rappel de l'existant (CONFIRMÉ)

| Fait | Preuve |
|---|---|
| Le Kiosk actuel est **une page dans le back-office organisateur** : route `box-office` enfant de `/manage/event/:eventId`, rendue dans le layout complet `components/layouts/Event` (sidebar ~25 entrées). | `frontend/src/router.tsx:411-416`, `frontend/src/components/layouts/Event/index.tsx:84-129` |
| Auth = **JWT Hi.Events**, transporté par un cookie `token` (`secure`, `SameSite=None`, `httpOnly`) posé par l'API à la connexion, **et** par header `X-Auth-Token`. | `backend/app/Http/Actions/Auth/BaseAuthAction.php:16-36` |
| Le front navigateur s'authentifie **par le cookie** (`withCredentials: true`, aucun interceptor n'ajoute `Authorization`). Le SSR, lui, lit `req.cookies.token` et le repositionne en `Bearer`. | `frontend/src/api/client.ts:31-37`, `frontend/src/entry.server.tsx:25`, `frontend/src/utilites/apiClient.ts` |
| Le guard `api` accepte le cookie : la chaîne de parsers JWT est `AuthHeaders, QueryString, InputSource` **+ `RouteParams` + `Cookies('token')`** ajoutés par le `LaravelServiceProvider` du package. `jwt.decrypt_cookies=false` et le groupe `api` n'a pas `EncryptCookies` → cookie lu en clair. | `vendor/php-open-source-saver/jwt-auth/src/Providers/LaravelServiceProvider.php:39-44`, `backend/app/Http/Kernel.php:70-76`, `backend/config/jwt.php:255` |
| Domaine du cookie = `config('session.domain')` = `env('SESSION_DOMAIN')`. | `backend/config/session.php:158` |
| CORS : `supports_credentials: true`, origines depuis `env('CORS_ALLOWED_ORIGINS')` (défaut `*`), `exposed_headers` inclut `X-Auth-Token`. | `backend/config/cors.php` |
| Rôles : `enum Role { SUPERADMIN, ADMIN, ORGANIZER }`. `ORGANIZER` est le plancher — `validateUserRole` ne vérifie que les seuils ADMIN/SUPERADMIN ; **tout rôle (même inconnu) passe le défaut `ORGANIZER`**. L'autorisation d'événement = `event.account_id === authAccountId`, **aucun périmètre par événement**. | `backend/app/DomainObjects/Enums/Role.php`, `backend/app/Services/Infrastructure/Authorization/IsAuthorizedService.php:38-85`, `backend/app/Http/Actions/BaseAction.php:160-176` |
| `account_users` : `role varchar(100)`, `status` (`INVITED`/`ACTIVE`/…), `unique(account_id, user_id, role)`, **pas de `event_id`**. Le token JWT porte `{account_id, role}`, `role` lu depuis cette table au login. Un `users` sans ligne `account_users` ACTIVE ne peut pas se connecter (`validateUserStatus` jette). | `backend/database/migrations/schema.sql:740-763`, `backend/app/Services/Domain/Auth/{LoginService,AuthUserService}.php` |
| Invitation d'utilisateur = `CreateUserAction` (`minimumAllowedRole(ADMIN)`) → `CreateUserHandler` → `AccountUserAssociationService::associate` (INVITED) → mail → `AcceptInvitationHandler` (mot de passe + ACTIVE). `role` limité à `getAssignableRoles()` = `[ADMIN, ORGANIZER]`. | `backend/app/Services/Application/Handlers/User/CreateUserHandler.php`, `backend/app/Services/Application/Handlers/Auth/AcceptInvitationHandler.php` |
| `ORGANIZER` a **déjà** accès plein : `EditEventSettingsAction`, `UpdateEventAction`, `ExportOrdersAction`, `GetEventsAction` (tous les événements du compte) — tous en `isActionAuthorized(..., EventDomainObject::class)` défaut `ORGANIZER`. Seules gestion users / réglages compte gâtent `Role::ADMIN`. | grep `app/Http/Actions` |
| `box_office_sales.agent_user_id` : `foreignId(...)->constrained('users')`, **NOT NULL**. `print_jobs.agent_user_id` : idem. Alimenté par `$this->getAuthenticatedUser()->getId()`. | `backend/database/migrations/2026_09_05_000000_...php:20`, `..._000001_...php:18`, `backend/app/Http/Actions/BoxOffice/CreateBoxOfficeSaleAction.php:33` |
| `box_office_sales` porte **un seul produit / palier / montant par ligne** (colonnes scalaires `product_id`, `product_price_id`, `amount`, `amount_collected`) et `order_id` est **`nullable()->unique()`** → 1 vente = 1 Order au plus. **Pas de `session_id`.** | `backend/database/migrations/2026_09_05_000000_...php:16-33` |
| `CreateAttendeeHandler::handle()` crée **toujours** un Order neuf (`createOrder()` inconditionnel, `is_manually_created = true`), dans **sa propre transaction**. | `backend/app/Services/Application/Handlers/Attendee/CreateAttendeeHandler.php:64-107,110-138` |
| Les ventes guichet **alimentent déjà `event_statistics`** : `CreateAttendeeHandler` émet `OrderStatusChangedEvent` → `UpdateEventStatsListener` → `UpdateEventStatisticsJob`. | `CreateAttendeeHandler.php:104`, `backend/app/Listeners/Event/UpdateEventStatsListener.php:10-16` |
| Précédent d'auth « appareil, pas utilisateur » : `AuthenticateScanDevice` (module `digit`) — token opaque `Bearer`/`X-Scan-Token`, stocké **hashé sha256** dans `digit_scan_devices`, provisionné par CLI artisan, scopable à une check-in list, jamais lié à un `users`. | `backend/modules/digit/src/Scan/Http/Middleware/AuthenticateScanDevice.php` |
| Précédent de « shell autonome hors back-office » : routes top-level `/check-in/:checkInListShortId` (layout `components/layouts/CheckIn`) et `/digit/scan/:checkInListShortId` (`pages/digit/ScannerDevicePage`), hors de l'arbre `/manage`. Les endpoints natifs `/public/check-in-lists/...` n'ont **aucune auth** (le `short_id` = capacité). | `frontend/src/router.tsx:617-632`, `backend/routes/api.php:501-502,544-549` |

---

## D22 — Sous-domaine `kiosk.picha.fr`

### Contexte (lu)

`CUSTOM_DOMAINS` dans `frontend/server.js:57-59` :

```js
const CUSTOM_DOMAINS = {
    'innocent976.yt': {organizerPath: '/events/6/innocent-event'},
};
```

Ce mécanisme est **minimal** et **inadapté tel quel** à un Kiosk :

- objet **codé en dur**, en mémoire du process SSR → toute nouvelle entrée = commit + rebuild + redéploiement (même dette que `themes/festival/events/somaroho.ts`, cf. `PICHA_BASELINE_TODO.md §T6) ;
- il ne fait qu'**une redirection 302** de `/` vers un `organizerPath` (`server.js:91-95`) puis sert **la même SPA, le même bundle, le même routeur** ;
- seul effet secondaire : override de `VITE_FRONTEND_URL` dans `window.hievents` pour ce host (`server.js:120-122`) ;
- aucune notion de « layout allégé », aucune restriction de routes, aucun changement d'auth.

Le front est **un seul bundle SSR, un seul `router.tsx`** avec code-splitting par route
(`frontend/src/router.tsx`, `entry.client.tsx:22-31`). Il existe déjà des **routes top-level hors
`/manage`** avec leur propre layout (`/check-in/:shortId`, `/digit/scan/:shortId`) — c'est le patron
d'un shell Kiosk autonome **dans le même dépôt/bundle**.

### Options

| # | Option | Détail | Impact |
|---|---|---|---|
| **A (recommandée)** | **Même app, shell dédié + garde par host.** `kiosk.picha.fr` sert le bundle existant ; `server.js` détecte le host `kiosk.*` et (au lieu d'un 302) force le point d'entrée `/kiosk` ; une route top-level `/kiosk/...` avec un layout minimal (`components/layouts/Kiosk`, pas la sidebar `Event`). Le host peut aussi restreindre : si `requestHost` est le Kiosk et l'URL n'est pas sous `/kiosk`, redirect vers `/kiosk`. | **[Infra]** 1 entrée DNS + vhost proxy → même conteneur `frontend`. **[Code]** moyen (nouveau layout + sous-arbre de routes). **[Sécu]** surface réduite côté navigateur (le back-office reste sur `app.picha.fr`). Réutilise React Query, i18n, `boxOfficeClient`, composants Mantine. |
| B | **Généraliser `CUSTOM_DOMAINS`** en table de config (host → {mode: 'kiosk' \| 'organizer', ...}) lue au runtime. | **[Code]** moyen (sortir la map en config/DB + cache). Résout aussi T6. Ne dit rien du layout allégé — à combiner avec A. |
| C | **App front séparée** (nouveau projet Vite/SSR déployé indépendamment, ne consommant que l'API). | **[Infra]** + **[Code]** élevés : dupliquer build, i18n, clients API, design system, pipeline SSR. 2 fronts à faire monter de version en parallèle. Bénéfice réel seulement si le Kiosk diverge fortement (offline, natif) — pas le cas au vu des captures. **Déconseillé** pour la v2. |

### CORS / cookie — où est fixé le domaine du cookie `token` (CONFIRMÉ par lecture de code)

Le Kiosk navigateur s'authentifie **par le cookie `token`** (D22 n'y change rien tant qu'on reste
sur l'auth JWT — voir D23). Question tranchée : **le domaine du cookie n'est PAS dans le JWT**, il
est calculé au moment où la réponse HTTP est construite, uniquement pour l'en-tête `Set-Cookie`.

**Chaîne exacte, ligne par ligne :**

| Étape | Fichier : ligne | Ce qui se passe |
|---|---|---|
| 1 | `backend/app/Http/Actions/Auth/BaseAuthAction.php:18-23` | `Cookie::make(name: 'token', value: $token, secure: true, sameSite: 'None')` — **l'argument `$domain` n'est pas passé** (donc `null`). Idem `$path`, `$expire`. |
| 2 | `vendor/laravel/framework/src/Illuminate/Cookie/CookieJar.php:64` puis `:202` | `make($name, $value, $minutes=0, $path=null, $domain=null, …)` → `getPathAndDomain()` retourne `$domain ?: $this->domain` → **`$this->domain`** (le défaut du jar). |
| 3 | `vendor/laravel/framework/src/Illuminate/Cookie/CookieServiceProvider.php:17-21` | `(new CookieJar)->setDefaultPathAndDomain($config['path'], $config['domain'], $config['secure'], …)` avec `$config = config('session')`. |
| 4 | `backend/config/session.php:158` | `'domain' => env('SESSION_DOMAIN')`. |

**Ce que le JWT contient** (aucune notion de domaine) :

| Source | Claims |
|---|---|
| `backend/app/Models/User.php:63-66` | `getJWTCustomClaims()` → `[]` |
| `backend/app/Services/Domain/Auth/LoginService.php` (`getToken()`) | `+ ['account_id' => …, 'role' => …]` |
| jwt-auth `PayloadFactory` | claims standard `iss, iat, exp, nbf, sub, jti` |

Le parser qui lit le cookie côté API (`vendor/php-open-source-saver/jwt-auth/.../LaravelServiceProvider.php:41` →
`new Cookies(...))->setKey('token')`) fait un simple `$request->cookie('token')` : il lit **la valeur**
que le navigateur envoie, **quel que soit** l'attribut `Domain` de ce cookie.

**Conséquence — prouvée, plus une supposition :**

Changer `SESSION_DOMAIN` (de host-only vers `.picha.fr`) modifie **uniquement les futurs en-têtes
`Set-Cookie`** — émis à la **prochaine connexion** (`auth/login`) ou au **prochain rafraîchissement
de jeton** (`auth/refresh` → `respondWithToken` → `addTokenToResponse` → `getAuthCookie`,
`RefreshTokenAction.php:11`). **Les sessions déjà ouvertes ne sont pas invalidées** : le navigateur
continue d'envoyer son cookie `token` existant, et l'API continue de l'accepter — le JWT lui-même
est inchangé, valide `JWT_TTL = 60*24*7` = **7 jours** (`config/jwt.php:92`), rafraîchissable
`JWT_REFRESH_TTL` = 14 jours (`:111`). Au prochain `auth/refresh`, le cookie est ré-émis avec le
nouveau `Domain` (transition transparente ; au pire un cookie `token` host-only et un cookie
`token` sur `.picha.fr` coexistent brièvement, tous deux porteurs d'un JWT valide — aucune
déconnexion). **Pas de re-login forcé.**

**Ce qu'il reste à faire pour `kiosk.picha.fr` → API :**

1. `SESSION_DOMAIN=.picha.fr` (avec le point) sur l'environnement cible **avant** la première
   connexion d'un opérateur au Kiosk. Sans re-login des sessions back-office en cours (voir ci-dessus).
   → **NON DÉTERMINÉ** : valeur actuelle en staging/prod (hors dépôt).
2. `CORS_ALLOWED_ORIGINS` liste explicitement `https://kiosk.picha.fr` (le défaut `*` est
   incompatible avec `supports_credentials: true` côté navigateur). → **NON DÉTERMINÉ** : valeur actuelle.
3. `SameSite=None; Secure` : déjà le cas (`BaseAuthAction.php:21-22`) → cross-site OK en HTTPS.

**Catégorie : CONFIRMÉ** (mécanisme du domaine du cookie, impact du changement) **+ NON DÉTERMINÉ**
(valeurs `SESSION_DOMAIN` / `CORS_ALLOWED_ORIGINS` en place ; topologie proxy : Kiosk et back-office
sur le même conteneur `frontend` ? API sur `api.picha.fr` ou `app.picha.fr/api` ?).

**Recommandation : A** (+ B pour éponger T6 au passage). Un seul bundle, un shell `/kiosk` allégé,
une garde par host dans `server.js`. `git revert` propre, pas de second pipeline.

---

## D23 — Compte opérateur de guichet : architecture — **VALIDÉE (Jo, 6 septembre 2026)**

### ✅ Décision actée

| Sous-décision | Choix retenu |
|---|---|
| **Architecture** | **Option 1** — rôle `BOX_OFFICE_OPERATOR` (compte `users` réel) + table `event_box_office_operators` + deux gardes dans `IsAuthorizedService` (`validateUserRole` négative + `validateBoxOfficeEventScope`). |
| **Création d'un compte opérateur** | **Réservée aux `ADMIN`** du compte (`minimumAllowedRole(Role::ADMIN)`). Un `ORGANIZER` ne peut **pas** créer d'opérateur. |
| **Rattachement à un événement** | **Multi-événements** : un opérateur peut avoir **plusieurs lignes** dans `event_box_office_operators`, une par événement. Contrainte `unique(event_id, user_id)` **par ligne** (pas d'affectation en double au même événement). |
| **Périmètre non retenu** | Pas d'`event_id` dans le token JWT, pas d'`event_id` sur `account_users`, pas de modification du `match ($entityType)`. |
| **`box_office_sales` / `print_jobs`** | **Inchangées** — `agent_user_id` reste une FK NOT NULL vers `users` (l'opérateur est un `users`). Aucune migration sur ces tables. |

**Conséquence UX actée :** après connexion, si l'opérateur a **plusieurs** événements actifs dans
`event_box_office_operators`, le shell Guichet affiche un **sélecteur d'événement** (restreint à ses
affectations) ; s'il n'en a **qu'un**, redirection directe vers la vente ; s'il n'en a **aucun**
(tous révoqués), écran « Aucun événement assigné ».

> **Cadrage d'origine (Jo, 6 septembre 2026) :** vrai compte opérateur, distinct de l'administrateur
> et de l'organisateur, accès **strictement limité au Kiosk** — pas de `/manage`, pas de réglages
> d'événement, pas d'exports, pas des autres événements. Compte rattaché à un/des événement(s)
> précis, pas à un compte organisateur entier. **Pas un raccourci** : la restriction est vérifiée
> au niveau API.

Ce cadrage **a écarté l'ancienne « option A »** (« ORGANIZER réel + UI bridée ») : la lecture du
code montre qu'elle ne protège rien au niveau API (voir §Contexte). L'analyse fichier par fichier
des deux architectures réelles est conservée ci-dessous pour la trace de décision ; **seule
l'Option 1 sera implémentée.**

### Contexte — ce que le code impose (CONFIRMÉ)

| Fait | Preuve |
|---|---|
| Le **token JWT porte `{account_id, role}`**. Le `role` est celui de l'utilisateur **dans ce compte**, lu depuis `account_users.role` au login et mis en claim. | `LoginService::getToken():96-120`, `AuthUserService::getAuthenticatedUserRole()`, `SetAccountContext.php` |
| `IsAuthorizedService::validateUserRole($minimumRole)` **ne lève que** si `minimumRole` vaut `ADMIN` (et l'utilisateur n'est ni ADMIN ni SUPERADMIN) ou `SUPERADMIN`. **Pour `minimumRole = ORGANIZER` — le défaut d'`isActionAuthorized` — AUCUN test : tout rôle passe, y compris une valeur d'enum inconnue.** | `IsAuthorizedService.php:38-49` |
| Conséquence directe : **ajouter une valeur à l'enum `Role` sans autre garde = l'opérateur obtient tous les droits `ORGANIZER`** (settings, exports, tous les événements du compte). | idem |
| `isActionAuthorized($eventId, EventDomainObject::class)` = `event.account_id === authAccountId`, **rien de plus**. Il n'existe **aucune notion de périmètre par événement**. | `IsAuthorizedService.php:51-85` |
| **`ORGANIZER` a déjà accès plein** : `EditEventSettingsAction:23`, `ExportOrdersAction:28`, `UpdateEventAction:29`, `GetEventsAction:26` (liste **tous** les événements du compte) — tous en `isActionAuthorized(..., EventDomainObject::class)` par défaut `ORGANIZER`. Seules la gestion des utilisateurs et des réglages de compte gâtent sur `Role::ADMIN` (`GetUsersAction:26`, `CreateUserAction:35`). | grep sur `app/Http/Actions` |
| **Chokepoint unique** : les 132 appels d'`isActionAuthorized` du code passent tous par `IsAuthorizedService` ; 94 avec `EventDomainObject`. Une règle ajoutée **là** couvre tout, de façon centralisée. | `grep -rn "isActionAuthorized(" app/Http/Actions` |
| `box_office_sales.agent_user_id` et `print_jobs.agent_user_id` : **FK NOT NULL vers `users`**, alimentées par `getAuthenticatedUser()->getId()`. | migrations `2026_09_05_000000/000001`, `CreateBoxOfficeSaleAction.php:33` |
| Pour se connecter, un utilisateur a besoin d'une ligne `account_users` **ACTIVE** : `LoginService` y lit `accountId` + `role` ; `IsAuthorizedService::validateUserStatus` **jette** si `getCurrentAccountUser()?->getStatus() !== ACTIVE`. Un opérateur doit donc avoir une ligne `account_users`. | `LoginService.php:44-60,133-155`, `IsAuthorizedService.php:114-122` |
| `account_users` : `unique (account_id, user_id, role)`, **pas de colonne `event_id`**. Rôle stocké en `varchar(100)` → une nouvelle valeur d'enum ne demande **aucune migration de colonne**. | `database/migrations/schema.sql:740-763` |
| Flux d'invitation existant : `CreateUserAction` (`minimumAllowedRole(ADMIN)`) → `CreateUserHandler` → `AccountUserAssociationService::associate(user, account, role, INVITED)` → mail → `AcceptInvitationHandler` (mot de passe + `ACTIVE`). `role` validé par `Rule::in(Role::getAssignableRoles())` = `[ADMIN, ORGANIZER]`. | `CreateUserHandler.php`, `AcceptInvitationHandler.php` |
| Précédent `AuthenticateScanDevice` (module `digit`) : acteur **non-`User`**. Token `Str::random(48)` → **sha256** dans `digit_scan_devices` (`account_id`, `event_id` nullable, `check_in_list_id` nullable, `revoked_at`, `all_check_in_lists`). Provisionné par `php artisan digit:scan:device:create`. Codes d'activation courts jetables (`DeviceActivationCodeService` : 8 car., TTL 15 min, usage unique, hashés). Le module **utilise `DB::table()` directement** (déroge au repository pattern `CLAUDE.md`), routes chargées sous `config('digit-scan.module_enabled')`. Middleware pose `request->attributes['digit_scan_device']`, **jamais** `Auth::user()`. | `modules/digit/src/Scan/Http/Middleware/AuthenticateScanDevice.php`, `.../Console/Commands/DigitScanDeviceCreateCommand.php`, `.../Devices/Domain/Services/DeviceActivationCodeService.php`, `modules/digit/database/migrations/2026_07_05_000000_*`, `.../2026_07_11_130000_*` |
| `check_in_lists` : simple FK `event_id` + `short_id`. Les endpoints natifs `/public/check-in-lists/{short_id}/...` n'ont **aucune** auth. Le module `digit` a **ajouté** son propre middleware par-dessus des routes parallèles `/digit/scan/...`. | `database/migrations/2024_08_08_032637_*`, `backend/routes/api.php:544-549`, `modules/digit/routes/scan.php` |

---

### Question 1 — Étendre l'enum `Role`, ou modèle d'auth séparé ?

#### Option 1 — Rôle `BOX_OFFICE_OPERATOR` (compte `users` réel) + scoping par événement

L'opérateur **est** un `users` + une ligne `account_users` (role = `BOX_OFFICE_OPERATOR`, status
`ACTIVE`), donc il se connecte par le flux JWT normal (`auth/login`, cookie `token`). Le périmètre
par événement vient d'une **table dédiée** `event_box_office_operators`. Deux gardes ajoutées **au
seul point central** `IsAuthorizedService`.

**Fichiers exacts à toucher :**

| Fichier | Nature | Détail |
|---|---|---|
| `backend/app/DomainObjects/Enums/Role.php` | modif | +`case BOX_OFFICE_OPERATOR = 'BOX_OFFICE_OPERATOR';` — **pas** dans `getAssignableRoles()` (on ne l'expose pas dans le modal d'invitation account-wide). |
| `backend/app/Services/Infrastructure/Authorization/IsAuthorizedService.php` | modif | (a) dans `validateUserRole()`, **en tête** : si le rôle courant est `BOX_OFFICE_OPERATOR` **et** `minimumRole !== BOX_OFFICE_OPERATOR` → `UnauthorizedException`. C'est la garde qui ferme **tout** (les 94 endpoints `EventDomainObject`, les 38 autres). (b) nouvelle méthode `validateBoxOfficeEventScope(int $eventId, UserDomainObject $user)` : si rôle ∈ {ADMIN, SUPERADMIN, ORGANIZER} → laisser passer (comme aujourd'hui) ; si `BOX_OFFICE_OPERATOR` → exiger une ligne `event_box_office_operators {event_id, user_id, status=ACTIVE}`. |
| `backend/app/Http/Actions/BaseAction.php` | modif | surcharge `isActionAuthorized()` pour accepter `Role::BOX_OFFICE_OPERATOR` en `minimumRole`, **ou** nouvelle méthode `isBoxOfficeActionAuthorized(int $eventId)` qui enchaîne `validateUserStatus` + `validateBoxOfficeEventScope`. (1 méthode, ~6 lignes.) |
| `backend/app/Http/Actions/BoxOffice/CreateBoxOfficeSaleAction.php`, `GetBoxOfficeTicketPdfAction.php`, `ReprintBoxOfficeTicketAction.php` | modif | remplacer `$this->isActionAuthorized($eventId, EventDomainObject::class)` par `$this->isBoxOfficeActionAuthorized($eventId)`. 1 ligne chacun. |
| `backend/database/migrations/xxxx_create_event_box_office_operators_table.php` | **nouveau** | `id`, `event_id` (FK cascade), `user_id` (FK), `created_by_user_id` (FK users), `status` (`ACTIVE`/`REVOKED`), `timestamps`, `unique(event_id, user_id)`. **1 table, ~10 colonnes.** |
| `backend/app/DomainObjects/…` + `generate-domain-objects` | auto | DO généré pour la nouvelle table. |
| `backend/app/Repository/{Interfaces,Eloquent}/EventBoxOfficeOperatorRepository*` | **nouveau** | CRUD standard (extends `BaseRepository`). |
| `backend/app/Http/Actions/BoxOffice/Operators/{Create,Get,Revoke}BoxOfficeOperatorAction.php` + Requests + Handlers | **nouveau** | création/liste/révocation d'opérateurs pour un événement (voir Question 4). **`minimumAllowedRole(Role::ADMIN)`.** Réutilise `CreateUserHandler`/`AccountUserAssociationService`. |
| `backend/routes/api.php` | modif | +3 routes `…/events/{event_id}/box-office/operators` (ADMIN) + `GET /box-office/context` (opérateur). |
| `backend/app/Http/Actions/BoxOffice/GetBoxOfficeContextAction.php` | **nouveau** | `GET /box-office/context` → **liste** des événements `ACTIVE` de l'opérateur connecté (`event_box_office_operators` JOIN `events`) : `[{id, title, currency, timezone}]`. Le shell décide : 0 / 1 / ≥2 (voir Question 4). Autorité **serveur**, pas de `localStorage`. |
| Tests : `tests/Unit/BoxOffice/BoxOfficeOperatorAuthorizationTest.php`, `tests/Feature/BoxOffice/{BoxOfficeOperatorScopeTest,BoxOfficeOperatorLifecycleTest}.php` | **nouveau** | opérateur → 403 sur `/events/{id}/orders`, `/events/{id}/settings`, `/events/{autreId}/box-office-sales`, `/manage`, création d'opérateur… ; 201 sur un événement assigné ; multi-événements ; révocation par événement. |
| **Aucune** modif de `box_office_sales` / `print_jobs` / `CreateBoxOfficeSaleHandler` / `CreateAttendeeHandler` | — | `agent_user_id` reste un `users.id` valide. |

#### Option 2 — Modèle d'auth séparé (patron `AuthenticateScanDevice`)

L'opérateur **n'est pas** un `users`. Table `box_office_operators` (ou `_terminals`), token opaque
hashé, middleware dédié sur les routes `box-office-*`.

**Fichiers exacts à toucher :**

| Fichier | Nature | Détail |
|---|---|---|
| `backend/…/Http/Middleware/AuthenticateBoxOfficeOperator.php` | **nouveau** | calqué sur `AuthenticateScanDevice` : `Bearer`/`X-BoxOffice-Token` → sha256 → lookup `box_office_operators` (`revoked_at IS NULL`), scope `event_id` vs route, pose `request->attributes['box_office_operator']`. |
| `backend/database/migrations/xxxx_create_box_office_operators_table.php` | **nouveau** | `id`, `name`, `account_id`, `event_id`, `token_hash` unique, `last_used_at`, `revoked_at`, `timestamps`. |
| `backend/database/migrations/xxxx_alter_box_office_sales_agent.php` | **nouveau — casse le slice 1** | `box_office_sales.agent_user_id` → **nullable** + `box_office_operator_id` (FK nullable) ; idem `print_jobs`. Contrainte applicative « l'un ou l'autre non nul ». |
| `backend/…/BoxOffice/CreateBoxOfficeSaleHandler.php` + `DTO/CreateBoxOfficeSaleDTO.php` + `ReprintBoxOfficeTicketHandler.php` | modif **cœur slice 1** | ne plus supposer `agent_user_id` ; router vers `agent_user_id` **ou** `box_office_operator_id` selon l'acteur. |
| `backend/…/BoxOffice/CreateBoxOfficeSaleAction.php` etc. | modif | lire l'identité depuis `request->attributes['box_office_operator']` au lieu de `getAuthenticatedUser()`. Deux chemins d'identité à maintenir (un ORGANIZER peut aussi vendre). |
| `backend/app/Resources/BoxOffice/BoxOfficeSaleResource.php` + resources d'audit/stats | modif | exposer l'opérateur (pas un `users`). |
| `backend/routes/api.php` | modif | groupe de routes `box-office-*` sous le nouveau middleware (au lieu de `auth:api`) — **ou** double routage (opérateur + ORGANIZER). |
| `backend/…/Console/Commands/BoxOfficeOperatorCreateCommand.php` (+ activation code) | **nouveau** | provisioning CLI, comme `digit:scan:device:create`. Pas d'UI par défaut. |
| Front | **nouveau** | écran d'activation par **code court** ou lien magique (pas de mot de passe — le « compte » est un token). Nouveau client API (le token n'est pas le cookie `token` JWT). |
| Tests | **nouveau** | middleware, scope, double chemin d'identité, non-régression `box_office_sales` nullable. |
| `IsAuthorizedService` | modif | il attend un `UserDomainObject` partout (`match` sur `entityType`, `getCurrentAccountUser()`) → il faut soit un adaptateur, soit court-circuiter pour ce middleware. |

**Verdict Question 1 :** **Option 1 est nettement plus petite** — 1 nouvelle table, 2 gardes dans 1
fichier central, **0 migration sur le cœur du slice 1**. Option 2 duplique le système d'auth
**et** impose une migration sur `box_office_sales`/`print_jobs` + une réécriture du handler du
slice 1. Option 2 ne se justifie que si le guichet doit fonctionner **sans jamais** aucun compte
nominatif (kiosque libre-service, personnel strictement jetable) — ce qui n'est pas le besoin
exprimé (« un vrai compte opérateur »).

---

### Question 2 — Rattachement à un événement précis : `IsAuthorizedService` extensible sans casse ?

**Oui, sans nouvelle couche, à une condition : ne pas surcharger le `match` par `entityType`.**

- Le `match ($entityType)` de `isActionAuthorized()` (`IsAuthorizedService.php:62-80`) est fermé
  (pas de `default`) : y ajouter un cas « périmètre événement » toucherait tous les appelants. **À
  éviter.**
- La bonne extension est **`validateUserRole()`** (déjà appelée en premier par `isActionAuthorized`,
  `IsAuthorizedService.php:59`) **+ une méthode sœur** `validateBoxOfficeEventScope()` appelée
  **uniquement** par les 3 actions box-office. Le reste du code est inchangé : pour un `ORGANIZER`,
  `validateBoxOfficeEventScope` est un no-op ; pour un `BOX_OFFICE_OPERATOR`, `validateUserRole`
  ferme déjà **tout le reste**.
- **Périmètre = table `event_box_office_operators`**, pas un claim JWT `event_id` : un lookup
  (indexé) par requête, mais permet la révocation immédiate (sans attendre l'expiration du token)
  et l'affectation à plusieurs événements sans re-login. Le claim `account_id` existant suffit pour
  `SetAccountContext`.
- **Ne pas** ajouter `event_id` à `account_users` : la relation `currentAccountUser()` (`HasOne
  where account_id`) et l'unicité `(account_id, user_id, role)` seraient à revoir partout. Table
  dédiée = concept isolé.

**Catégorie : CONFIRMÉ** — l'extension est possible sans nouvelle couche d'autorisation, via
`validateUserRole` (garde négative) + `validateBoxOfficeEventScope` (garde positive, table dédiée).

---

### Question 3 — Impact sur `box_office_sales.agent_user_id` / `print_jobs.agent_user_id`

| | Option 1 (rôle `BOX_OFFICE_OPERATOR`) | Option 2 (auth séparée) |
|---|---|---|
| `agent_user_id` (FK NOT NULL `users`) | **Intacte.** L'opérateur est un `users` → `getAuthenticatedUser()->getId()` renvoie un id valide. **Zéro migration**, l'audit trail du slice 1 (tests `ReprintTest`, `BoxOfficeEndToEndTest`) reste vert sans retouche. | **Cassée.** Il faut rendre `agent_user_id` **nullable** + ajouter `box_office_operator_id` (FK) sur `box_office_sales` **et** `print_jobs`, + adapter `CreateBoxOfficeSaleHandler`, `ReprintBoxOfficeTicketHandler`, `BoxOfficeSaleResource`, les DTO, et tous les tests du slice 1. |
| Rapports / stats par opérateur (D28-B) | `agent_user_id` → `users` → nom, e-mail dispo directement. | jointure sur la nouvelle table, `users` absent. |
| Risque | nul sur l'existant | **régression sur du code livré et testé** |

**Catégorie : CONFIRMÉ** — Option 1 = **aucune migration** sur `box_office_sales`/`print_jobs`.
Option 2 = migration obligatoire sur ces deux tables **+** réécriture du handler du slice 1.

---

### Question 4 — Comment un ADMIN/ORGANIZER crée-t-il un compte opérateur pour un événement donné ?

**Option 1 — réutilise l'infra d'invitation existante, avec un flux dédié à l'événement :**

1. Écran **« Opérateurs de guichet »** — **réservé aux `ADMIN`** — dans la zone Box Office de
   l'événement (`/manage/event/:eventId/box-office/operators`). Liste des opérateurs de l'événement
   + bouton « Inviter un opérateur ». (Un opérateur déjà existant sur un autre événement peut être
   ré-assigné à celui-ci : ajout d'une ligne `event_box_office_operators`, pas un nouveau `users`.)
2. `POST /events/{event_id}/box-office/operators {first_name, last_name, email}` →
   `CreateBoxOfficeOperatorHandler` (1 transaction) :
   - `users` : réutiliser l'existant si l'e-mail existe déjà, sinon `create(['password' => 'invited', …])` (comme `CreateUserHandler::createUser`) ;
   - `account_users` : `AccountUserAssociationService::associate(user, account, Role::BOX_OFFICE_OPERATOR, status: INVITED)` **si la ligne n'existe pas déjà** — **pas** dans `getAssignableRoles()` (donc pas exposable dans le modal account-wide) ;
   - `event_box_office_operators` : `INSERT {event_id, user_id, created_by_user_id, status: ACTIVE}` ; **`unique(event_id, user_id)`** → une 2ᵉ invitation au même événement est un no-op / 409 clair ;
   - `SendUserInvitationService::sendInvitation(...)` — **mail d'invitation existant**, inchangé (seulement si le `users` vient d'être créé ou est encore `INVITED`).
3. L'opérateur clique le lien, `AcceptInvitationHandler` **inchangé** : il pose son mot de passe,
   `account_users.status → ACTIVE`. Il se connecte ensuite sur `/kiosk/login`.
4. **Autorisation de l'action de création : `minimumAllowedRole(Role::ADMIN)`** (décision actée).
   Un `ORGANIZER` reçoit 403. `CreateUserAction` gâte déjà `ADMIN` de la même façon
   (`CreateUserAction:35`) — cohérence.
5. **Révocation** : `PATCH …/operators/{id} {status: REVOKED}` (ADMIN) → la ligne
   `event_box_office_operators` de **cet événement** passe à `REVOKED` ; les autres affectations de
   l'opérateur restent actives. Le `users` et le `account_users` **restent** — intégrité de l'audit
   trail des ventes passées. Révoquer la **dernière** affectation ⇒ l'opérateur n'a plus aucun
   événement → il voit l'écran « Aucun événement assigné » à la connexion.
6. **Pas d'auto-inscription** : aucune route publique, le flux part toujours d'un `ADMIN` authentifié.

**Multi-événements — impact shell :** `GET /box-office/context` (voir Option 1, tableau) renvoie
**la liste** des événements `ACTIVE` de l'opérateur. Le shell : 0 → écran « Aucun événement
assigné » ; 1 → redirection directe `/kiosk/event/:id/sell` ; ≥2 → écran `/kiosk/select-event`
(liste restreinte à ses affectations), puis navigation dans l'événement choisi. Changer d'événement
= retour au sélecteur (pas de re-login).

---

### Recommandation D23 — **VALIDÉE, voir « ✅ Décision actée » en tête de section**

**Option 1** retenue. Rappel des raisons (preuves à l'appui) :

1. **Plus petite migration** : 1 table neuve (`event_box_office_operators`), **0 migration** sur
   `box_office_sales`/`print_jobs`, **0 retouche** de `CreateBoxOfficeSaleHandler` /
   `CreateAttendeeHandler`. Option 2 aurait imposé une migration sur le cœur du slice 1 livré.
2. **Séparation réelle, pas cosmétique** : la garde négative dans `validateUserRole` ferme les 132
   endpoints d'un coup ; l'opérateur qui `curl` `/events/{id}/orders`, `/events/{id}/settings`,
   `/events/{autreId}/…` ou `/manage/*` reçoit **403 côté API**.
3. **Rattachement multi-événements** natif via `event_box_office_operators` (N lignes,
   `unique(event_id, user_id)`), sans toucher `account_users` ni le `match` d'`isActionAuthorized`.
4. **Réutilise le socle d'identité** : mot de passe, invitation, reset, `AcceptInvitationHandler`,
   cookie JWT, `agent_user_id`. Pas de second système de credentials à durcir.
5. **Chokepoint unique** (`IsAuthorizedService`, 132 appels) → surface de revue de sécurité petite
   et bien délimitée.

**Catégorie : ✅ DÉCIDÉE** (Option 1 ; création réservée `ADMIN` ; multi-événements + sélecteur).

---

## D15 (réouverture) — Session de caisse

> **✅ Statut (Jo, 6 sept. 2026) : BACKLOG confirmé. Ne pas traiter en v2.1 — ne pas toucher.**
> L'analyse ci-dessous reste la référence pour le jour où le chantier sera ouvert.

**Slice 1 : tranché A** (pas de session de caisse ; chaque vente porte `agent_user_id` + `created_at`).
Les captures Weezevent (capture 4) montrent : **ouverture de session, n° de session, suspension, fin
de session, stats par opérateur et par session**. Cela **rouvre D15** — **hors périmètre v2.1**.

### Ce que le code impose aujourd'hui (CONFIRMÉ)

- **`box_office_sales` n'a pas de `session_id`** (la reco D3 le mentionnait, la migration livrée ne
  l'a pas — `2026_09_05_000000_...php`). Une session de caisse = **nouvelle migration** :
  `box_office_cash_sessions {id, event_id, agent_user_id, opened_at, opened_float, closed_at,
  closed_total_declared, closed_total_expected, status}` + `box_office_sales.session_id` (nullable
  FK) + backfill (les ventes slice 1 resteront `session_id = null`).
- Agrégats « par session » / « par opérateur » : **aucune requête n'existe** (ni endpoint, ni
  service). `EventStatsFetchService` ne connaît que `event_statistics` (pas de dimension opérateur /
  méthode de paiement / session).
- « Suspension » (pause) : nouvel état + horodatage ; impacte l'UX de reprise (le poste doit
  retrouver sa session ouverte au rechargement — cf. D29 config locale).

### Options (esquisse, non tranchées)

| # | Piste | Impact |
|---|---|---|
| A | Rester sans session formelle en v2.1, ajouter seulement un **rapport « par opérateur / par plage horaire »** a posteriori (agrégat SQL sur `box_office_sales.agent_user_id, created_at, payment_method`). | **[Code]** faible. Couvre 80 % du besoin « combien a encaissé Untel aujourd'hui ». Pas de fond de caisse / écart. |
| B | Table `box_office_cash_sessions` complète (ouverture/fond/clôture/écart) + `session_id` sur les ventes + écrans ouverture/suspension/clôture. | **[Schéma]** + **[UX]** + **[Code]** significatifs. Nécessaire si PICHA fait de la **remise d'espèces formelle** avec comptage. |
| C | B **sans** le volet espèces (juste un identifiant de session pour regrouper/filtrer, sans fond de caisse). | Intermédiaire ; utile pour les stats « par session » sans la charge du rapprochement de caisse. |

**Catégorie : DÉCISION REQUISE** — Jo tranche A / B / C **+ NON DÉTERMINÉ** : PICHA fait-il du
comptage d'espèces formel au guichet (fond de caisse, écart signé en fin de service) ? Si non → A/C
suffisent. Si oui → B.

---

## D19 (réouverture) — Matériel / poste / TPE

**Slice 1 : D19 tranchée le 2026-09-05** — poste agent macOS (cible Windows ensuite), ZD621 en
réseau/IP (USB fallback), impression PDF + `window.print()`, abstraction `TicketRenderer` sans ZPL.
Les captures rouvrent deux sujets **connexes mais distincts**, **à ne pas re-trancher ici** :

### D19a — Réglages matériels par poste (capture 5)

Weezevent : écran de réglages **explicitement local à l'appareil** (imprimante, options d'envoi
email, langue), **pas au compte**. Voir **D29** ci-dessous (traité séparément car c'est surtout un
sujet front/stockage local).

### D19b — Paiement TPE intégré via API (capture 6)

Weezevent : « lancer le paiement » puis « confirmer quand c'est OK côté terminal ».

**Ce que le code dit (CONFIRMÉ) :**
- **Rien n'existe.** `enum PaymentProviders` = `STRIPE` / `OFFLINE` uniquement
  (`backend/app/DomainObjects/Enums/PaymentProviders.php`). Aucune intégration terminal.
- Le slice 1 `payment_method ∈ {CASH, CARD, FREE}` est **un simple libellé déclaratif** :
  `CARD` = « encaissé sur un TPE externe, non intégré », l'opérateur saisit `amount_collected` à la
  main (`CreateBoxOfficeSaleHandler.php:65-75`). Aucun lien avec un terminal.
- `CreateBoxOfficeSaleHandler` est **synchrone et atomique** : il valide, crée l'Attendee, marque la
  vente `COMPLETED`, tout dans une transaction. Un flux TPE (« lancer → attendre confirmation
  terminal → puis créer le billet ») est **asynchrone par nature** et ne rentre pas dans ce handler
  tel quel.

**Esquisse d'options (non tranchées) :**

| # | Piste | Impact |
|---|---|---|
| A | **Statu quo** : `CARD` reste déclaratif, l'opérateur tape le TPE physiquement puis valide. | **[Code]** nul. Pas de rapprochement automatique encaissement ↔ billet. |
| B | Intégration **TPE via API cloud** (Stripe Terminal, SumUp, PayGreen, Adyen Tap…) : nouvel état `box_office_sales.status = AWAITING_PAYMENT`, endpoint « initier paiement » → renvoie un `payment_intent` terminal, polling/webhook « paiement confirmé » → endpoint « finaliser » qui appelle la logique de création du billet. | **[Code]** élevé (machine à états, webhooks, réconciliation, gestion timeout/annulation terminal). **[Schéma]** états + réf transaction. **[Sécu/Compta]** fort gain (montant garanti = montant billet). Dépend du **choix du prestataire TPE** (hors dépôt). |
| C | Intégration **TPE local** (SDK sur le poste, ex. lecteur BT) piloté par un agent local, le back n'orchestre pas. | **[Code]** front/agent local élevé, back faible. Contraint l'OS du poste (cf. D19 macOS→Windows). |

**Catégorie : DÉCISION REQUISE** (rouvrir D19b : A / B / C) **+ NON DÉTERMINÉ** : PICHA a-t-il déjà
un parc TPE / un prestataire monétique imposé ? modèle des terminaux ? Sans cette info, B n'est pas
spécifiable. **Recommandation de séquencement : garder A pour la v2.1**, traiter B comme un chantier
dédié une fois le prestataire connu.

**Backlog Jo (6 sept. 2026, hors v2.1) :** le kiosque devra **communiquer** avec **MVola**,
**Orange Money** et les **TPE**. **Carte** (déjà à l'encaissement) = le TPE plus tard — pas de
second bouton. Placeholders grisés **MVola / Orange Money** sur l'étape Paiement du kiosque.
Aucune API, aucun `payment_method` nouveau.

---

## D25 — Panier multi-billets (lien avec D4)

### Contexte (lu)

Weezevent (capture 3) : **panier modifiable avant paiement** — quantité +/‑, ajout, suppression,
plusieurs lignes. Le slice 1 fait **1 billet = 1 appel** (`CreateBoxOfficeSaleAction` →
`CreateBoxOfficeSaleHandler`, DTO scalaire `CreateBoxOfficeSaleDTO` : un `product_id`, un
`product_price_id`, un `amount`).

**D4 (`PICHA_BOX_OFFICE_DECISIONS_REQUIRED.md`) est déjà documentée** — options A (boucle côté
agent), B (boucle N × `CreateAttendeeHandler` en transaction englobante, 1 Order/billet), C (nouveau
handler `CreateBoxOfficeOrderHandler` : 1 Order, N items). D4 avait été **reportée après le slice 1**.

### Le slice 1 actuel peut-il être appelé en boucle depuis le panier, sans modification ?

**Réponse : NON, pas sans modification** — et ce pour trois raisons vérifiées, pas une :

1. **Schéma `box_office_sales`** (`2026_09_05_000000_...php:16-33`) : une ligne = **un** produit, **un**
   palier, **un** montant, et `order_id` est **`unique()`**. Un panier de 3 lignes ne peut pas être
   représenté par une ligne `box_office_sales`. Il faudrait soit 3 lignes (1 par billet, reliées par
   un identifiant de panier), soit une table `box_office_sale_items`.
2. **Idempotence** : `CreateBoxOfficeSaleHandler` traite **une** `idempotency_key` = **une** vente
   complète (`findCompletedSale`, insert unique, `CreateBoxOfficeSaleHandler.php:55-92`). Boucler
   l'appel HTTP actuel depuis le front avec N clés = N ventes indépendantes ; si l'opérateur relance
   le panier après une panne au 2ᵉ billet, les 2 premiers se re-créent **ou** l'idempotence par
   billet évite le doublon mais on perd l'atomicité « le panier passe en entier ou pas du tout ».
   Le besoin métier « panier » veut **une** clé d'idempotence pour **toute l'opération**.
3. **`CreateAttendeeHandler` crée 1 Order par appel** (`CreateAttendeeHandler.php:64-70,110`). Une
   « vente » de 4 billets famille = **4 Orders COMPLETED distincts** (4 mails de confirmation, 4
   factures potentielles, 4 lignes dans l'écran Commandes). C'est le point explicitement noté en D4-B.

### Options (reprise de D4, à re-valider)

| # | Option | Détail | Impact |
|---|---|---|---|
| A | Panier **UI seulement**, N appels séquentiels au endpoint slice 1 inchangé, N clés d'idempotence. | **[Code]** front only. **[Schéma]** nul. **[UX]** panier visible mais « validation » = N requêtes ; échec partiel possible ; N Orders. **Le plus rapide**, sémantique faible. |
| **B (recommandée v2)** | Nouveau endpoint `POST /events/{id}/box-office-sales` **acceptant `items[]`** + **1** `idempotency_key`. Handler : 1 transaction englobante, **1 ligne `box_office_sales` "parent"** + `box_office_sale_items[]` (ou N lignes reliées par `cart_id`), boucle sur `CreateAttendeeHandler`. Reste **N Orders** (assumé) mais **1 opération / 1 clé / tout-ou-rien**. | **[Code]** moyen. **[Schéma]** 1 migration (`box_office_sale_items` **ou** `cart_id` + drop du `unique` sur `order_id`). **[UX]** bon. Réutilise `CreateAttendeeHandler` intact (pas de dette de double chemin). |
| C | `CreateBoxOfficeOrderHandler` : **1 Order, N OrderItems, N Attendees**. | **[Code]** élevé (réimplémente la création Order/Item/Attendee hors `CreateAttendeeHandler` → 2 chemins à maintenir, cf. `DESIGN_OPTIONS` Option B). **[Compta]** le plus propre (1 facture famille). |

**Catégorie : DÉCISION REQUISE** — Jo tranche A / B / C (D4 rouverte pour la v2). **NON DÉTERMINÉ :**
PICHA a-t-il besoin d'**une facture unique** pour un lot (familles, groupes, CE) ? Si oui → C ;
sinon → B.

**Recommandation : B** — nouveau handler multi-items **au-dessus** de `CreateAttendeeHandler`
(pas à la place), N Orders assumés, 1 clé d'idempotence, 1 transaction. C reste la cible si la
facture unique devient un besoin.

---

## D26 — Navigation par onglets fixes (Vente / Commandes / Statistiques / Réglages)

### Contexte (lu)

Le layout actuel (`components/layouts/Event`) est une **sidebar dense** de ~25 entrées groupées
(`Event/index.tsx:84-129`), inadaptée à un poste de guichet tactile. Weezevent = **4 onglets fixes**.

### Options

| # | Option | Détail | Impact |
|---|---|---|---|
| **A (recommandée)** | Nouveau layout `components/layouts/Kiosk` : barre d'onglets fixe (Mantine `Tabs` ou `SegmentedControl` + `NavLink`), routes enfant sous `/kiosk/event/:eventId/{sell,orders,stats,settings}`, **précédées d'un `/kiosk/select-event`** quand l'opérateur a ≥2 événements. `eventId` **jamais** choisi librement : il vient de `GET /box-office/context` (D23), l'opérateur ne voit que ses affectations. | **[Code]** moyen (layout + routes + sélecteur). Cohérent avec D22-A. |
| B | Réutiliser le layout `Event` en filtrant `navItems` selon le host/rôle. | **[Code]** faible mais **[UX]** faible : on garde une sidebar, pas des onglets ; fragile (chaque nouvelle entrée `navItems` fuit dans le Kiosk si on oublie de la filtrer). |

**Sélecteur d'événement (D23, acté) :** après `/kiosk/login`, le shell appelle `GET /box-office/context`.
**0** événement → écran « Aucun événement assigné » (l'ADMIN doit assigner l'opérateur) ;
**1** → redirection directe `/kiosk/event/:id/sell` ;
**≥2** → `/kiosk/select-event` (cartes des événements assignés, `ACTIVE` uniquement). Changer
d'événement en cours de service = revenir au sélecteur, **sans** re-login.

**Conséquence de D23 Option 1 (CONFIRMÉ) :** un `BOX_OFFICE_OPERATOR` **ne peut pas** appeler les
endpoints natifs `GET /events/{id}/orders` ni `GET /events/{id}/stats` (gâtés `ORGANIZER`, fermés
par la garde `validateUserRole`). Les onglets **Commandes** et **Statistiques** exigent, pour
l'opérateur, de **nouveaux endpoints box-office-scoped** (D27-B / D28-B).

**Recadrage acté (session 6 sept. 2026) :** Commandes et Statistiques sont **backlog pour cette
itération, y compris pour un ORGANIZER**. Le shell v2.1 n'a que **2 onglets : Vente + Réglages**,
pour tout le monde. Ne pas les ajouter sans nouvelle validation explicite.

**Catégorie : ✅ DÉCIDÉE** (A, puis recadrage) — v2.1 = **Vente + Réglages** pour opérateur **et**
ORGANIZER/ADMIN. Commandes/Stats = backlog, même pour un ORGANIZER.

---

## D27 — Écran « Commandes » : réutilisation de l'existant

### Endpoints / queries natifs déjà présents (CONFIRMÉ)

| Besoin capture 7 | Déjà fourni par | Preuve |
|---|---|---|
| Liste des commandes d'un événement, paginée | `GET /events/{event_id}/orders` → `GetOrdersAction` | `backend/routes/api.php:367`, `backend/app/Http/Actions/Orders/GetOrdersAction.php` |
| **Recherche nom / email / n° de commande** | paramètre `query` → `ILIKE` sur `(first_name||' '||last_name)`, `last_name`, `public_id`, `email` | `backend/app/Repository/Eloquent/OrderRepository.php:38-52` |
| **Filtre** (statut, statut paiement, statut remboursement, dates, montant) | `filter_fields` sur `OrderDomainObject::getAllowedFilterFields()` = `STATUS, PAYMENT_STATUS, REFUND_STATUS, CREATED_AT, FIRST_NAME, LAST_NAME, EMAIL, PUBLIC_ID, CURRENCY, TOTAL_GROSS` | `backend/app/DomainObjects/OrderDomainObject.php:39-52` |
| **Tri** (date, nom acheteur, montant, email, n°) | `OrderDomainObject::getAllowedSorts()` | `OrderDomainObject.php:55-78` |
| Détail commande | `GET /events/{event_id}/orders/{order_id}` → `GetOrderAction` | `backend/routes/api.php:368` |
| Remboursement / annulation | `POST .../refund` (`RefundOrderAction`), `.../cancel` (`CancelOrderAction`) | `backend/routes/api.php:371,373` |
| Renvoi de confirmation | `POST .../resend_confirmation` | `backend/routes/api.php:372` |
| Export | `POST /events/{event_id}/orders/export` → `ExportOrdersAction` | `backend/routes/api.php:374` |
| Métadonnées de filtres/tri renvoyées au front | `filterableResourceResponse` ajoute `allowed_filter_fields`, `allowed_sorts`, `default_sort` au `meta` | `backend/app/Http/Actions/BaseAction.php:42-61` |

**Composants front déjà existants** (`frontend/src/components/routes/event/orders.tsx`) :
`OrdersTable`, `SearchBarWrapper`, `Pagination`, `FilterModal`/`FilterOption`, `ToolBar`,
`useGetEventOrders`, `orderClient.exportOrders`, `useFilterQueryParamSync`.

### Écart réel (DÉCISION REQUISE)

Le seul manque : **filtrer « commandes du guichet uniquement »**. `CreateAttendeeHandler` pose
`orders.is_manually_created = true` (`CreateAttendeeHandler.php:129`) mais `IS_MANUALLY_CREATED`
**n'est pas** dans `getAllowedFilterFields()`. Deux pistes :

| # | Piste | Impact |
|---|---|---|
| A | Ajouter `IS_MANUALLY_CREATED` (ou mieux : un vrai marqueur `channel`/`source` sur `orders`) aux `getAllowedFilterFields`. | **[Code]** trivial (1 ligne + test). Attention : `is_manually_created` couvre **toutes** les créations manuelles (dont l'ajout d'attendee natif), pas seulement le guichet. |
| B | Écran Commandes du Kiosk = **jointure sur `box_office_sales`** (nouvelle query `GET /events/{id}/box-office-sales?...`) → n'affiche que les ventes guichet, avec `payment_method`, `agent_user_id`, `amount_collected` en colonnes (que l'écran Orders natif n'a pas). | **[Code]** moyen (nouvel endpoint + repo + resource + query front). Plus fidèle aux captures (colonnes « opérateur », « encaissé »). |

**Conséquence de D23 Option 1 :** la réutilisation « telle quelle » de `GET /events/{id}/orders`
vaut **pour un ORGANIZER** connecté au shell. **Pour un `BOX_OFFICE_OPERATOR`**, ce endpoint renvoie
403 → si l'opérateur doit voir les commandes, la piste **B devient obligatoire** (endpoint
`GET /events/{id}/box-office-sales` autorisé par `validateBoxOfficeEventScope`, ne renvoyant que ses
ventes guichet). En v2.1, l'onglet Commandes est **hors périmètre pour tout le monde** (backlog,
y compris ORGANIZER — recadrage 6 sept. 2026).

**Catégorie : CONFIRMÉ** (réutilisable tel quel pour un ORGANIZER) **+ DÉCISION REQUISE**
(A : Orders natif + filtre `is_manually_created`, réservé ORGANIZER ; B : endpoint box-office-scoped,
nécessaire pour donner l'écran à l'opérateur). **Recommandation : A pour l'accès ORGANIZER,
B en backlog pour l'opérateur.**

---

## D28 — Écran « Statistiques » : réutilisation de l'existant

### Ce qui existe (CONFIRMÉ)

| Besoin capture 8 | État |
|---|---|
| Synthèse ventes événement (CA, billets vendus, commandes, taxes, frais, **remboursements**), série journalière | **Déjà fourni** : `GET /events/{event_id}/stats` → `GetEventStatsHandler` → `EventStatsFetchService` lit `event_statistics` / `event_daily_statistics`. Renvoie `total_gross_sales, total_products_sold, total_orders, total_tax, total_fees, total_refunded, attendees_registered` + `daily_stats[]`. Front : `useGetEventStats`, écran `dashboard`. | `backend/app/Services/Domain/Event/EventStatsFetchService.php:24-72` |
| **Les ventes guichet y sont déjà incluses** | `CreateAttendeeHandler` → `OrderStatusChangedEvent` → `UpdateEventStatsListener` (ne compte que les Orders `COMPLETED`) → `UpdateEventStatisticsJob`. | `UpdateEventStatsListener.php:10-16` |
| Check-in : total / scannés | `EventStatsFetchService::getCheckedInStats()` | idem `:171-192` |
| Remboursements / annulations en colonne | `event_statistics.total_refunded` (niveau événement) ; par commande : `orders.refund_status`. Services dédiés : `EventStatisticsRefundService`, `EventStatisticsCancellationService`. | — |
| Rapports organisateur multi-événements | `/manage/organizer/:id/reports`, `report/:reportType`, `EventsPerformanceReport` (infra de reporting existante) | `frontend/src/router.tsx:317-327` |

### Écart réel vs Weezevent (DÉCISION REQUISE)

Weezevent croise **« Ventes vs Caisse », par opérateur, par session**. Or :

- **`event_statistics` n'a aucune dimension** `payment_method` / `agent_user_id` / `session_id`.
  Ces axes n'existent **que** dans `box_office_sales` (`payment_method`, `agent_user_id`,
  `amount_collected`) — et il **n'y a aucun endpoint d'agrégation** dessus aujourd'hui, ni
  `session_id` (cf. D15).
- « Ventes » (valeur des billets, `amount`) vs « Caisse » (encaissé, `amount_collected`) : les deux
  colonnes existent en base par ligne, jamais agrégées.
- `total_refunded` est global — **pas** ventilable par opérateur sans nouveau calcul.

| # | Piste | Impact |
|---|---|---|
| A | Écran Stats Kiosk = **l'écran natif `stats` tel quel** (CA, billets, remboursements, série journalière), sans la vue « par opérateur / caisse ». | **[Code]** ~nul (réutilise `useGetEventStats`). Couvre « comment se vend l'événement », pas « qui a encaissé quoi ». |
| B | **Nouveau** `GET /events/{id}/box-office-stats` : agrégat SQL sur `box_office_sales` (par `agent_user_id`, `payment_method`, `date`, et `session_id` si D15 le crée) → totaux ventes vs encaissé, nb billets, remboursements guichet. | **[Code]** moyen (1 service d'agrégation + resource + query front + écran). C'est **le vrai apport v2** de cet écran. Dépend en partie de D15 (session) et D27-B (refund guichet). |
| C | A + B (natif pour la synthèse événement, dédié pour le volet caisse/opérateur). | **[Code]** = B. **Recommandé en cible.** |

**Conséquence de D23 Option 1 :** `GET /events/{id}/stats` est gâté `ORGANIZER` → 403 pour un
`BOX_OFFICE_OPERATOR`. L'onglet Statistiques (piste A) est donc **réservé ORGANIZER** ; pour le
donner à l'opérateur il faut la piste **B** (`GET /events/{id}/box-office-stats` autorisé par
`validateBoxOfficeEventScope`). En v2.1, l'onglet Statistiques est **hors périmètre pour tout
le monde** (backlog, y compris ORGANIZER — recadrage 6 sept. 2026).

**Catégorie : CONFIRMÉ** (synthèse événement réutilisable pour un ORGANIZER) **+ DÉCISION REQUISE**
(B, et quand — après D15 ?). **Recommandation : A pour l'accès ORGANIZER, B/C ensuite** (après D15 —
la maille « par session » n'a de sens qu'une fois la session définie).

---

## D29 — Réglages par poste, locaux à l'appareil

### Contexte (lu)

Weezevent (capture 5) : réglages **explicitement locaux au poste** (imprimante, options d'envoi
email, langue) — **pas** stockés sur le compte. Aujourd'hui le Kiosk slice 1 n'a **aucun** réglage :
`send_confirmation_email: false` est **codé en dur** (`CreateBoxOfficeSaleHandler.php:113`), la
langue du billet est un champ du formulaire par vente (`BoxOffice/index.tsx:77,317-325`),
l'impression passe par `window.open(...).print()` sans configuration (`BoxOffice/index.tsx:46-50`).

L'app est **SSR** — `window`/`document`/`localStorage` doivent être gardés (`CLAUDE.md` : « ensure
safe usage of `window` and `document` »). Le patron de stockage local existe déjà dans le dépôt
(`sessionStorage` pour `session_identifier` du checkout, cf. `PICHA_BASELINE_TODO.md §T3` commit
`64fc793b`).

### Options

Avec D23 Option 1, **le choix de l'événement n'est plus un réglage** : il est porté par le compte
opérateur (`event_box_office_operators`) et résolu côté serveur (`GET …/box-office/context`). Le
`localStorage` ne garde donc que des **préférences d'affichage/impression**, jamais l'identité ni
le périmètre.

| # | Option | Détail | Impact |
|---|---|---|---|
| **A (recommandée)** | Préférences du poste en **`localStorage`** (clé `picha_kiosk_settings`), écran `/kiosk/.../settings` : imprimante/format, `send_confirmation_email` par défaut, langue par défaut du billet, nom d'affichage du poste. Lu au montage client, jamais en SSR. `eventId` **non** stocké ici (vient de l'auth). | **[Code]** faible (front only). **[Schéma]** nul. Conforme aux captures (local à l'appareil). |
| B | Préférences côté serveur, table `box_office_terminals`. | **[Schéma]** + **[Code]**. Contredit l'intention Weezevent (« pas au compte »), utile seulement si PICHA veut administrer les postes à distance. |

**Catégorie : DÉCISION REQUISE** (A / B) — **Recommandation : A**. Le point sensible « poste mal
configuré → vend sur le mauvais événement » **disparaît** avec D23 Option 1 (l'événement vient du
compte, pas d'un réglage local).

---

## Récapitulatif des décisions requises

| # | Sujet | Recommandation | Nature | Dépendances / à obtenir |
|---|---|---|---|---|
| **D22** | Sous-domaine `kiosk.picha.fr` | **A** : même bundle, shell `/kiosk` allégé, garde par host dans `server.js` (+ B pour généraliser `CUSTOM_DOMAINS`) | Infra + Code | Valeurs `SESSION_DOMAIN`, `CORS_ALLOWED_ORIGINS` en place ; topologie proxy/API |
| **D23** | Compte opérateur de guichet | **✅ VALIDÉE** — Option 1 : rôle `BOX_OFFICE_OPERATOR` (compte `users` réel) + table `event_box_office_operators` (multi-événements, `unique(event_id, user_id)`) + garde négative `validateUserRole` + `validateBoxOfficeEventScope`. Création **réservée ADMIN**. Sélecteur d'événement si ≥2. 1 table neuve, 0 migration sur `box_office_sales`/`print_jobs`. | Schéma (1 table) + Code moyen | **Aucune** — décidé. Prêt à implémenter. |
| **D15** | Session de caisse | ✅ **BACKLOG confirmé (Jo, 6 sept.)** — ne pas traiter en v2.1 | — | — |
| **D19b** | Paiement TPE intégré (réouvert) | Garder **A** (déclaratif) pour la v2.1 ; **B** (TPE API) = chantier dédié | Code élevé (B) | Prestataire monétique / parc TPE PICHA ? |
| **D25** | Panier multi-billets (D4 rouverte) | **B** : nouveau handler multi-items au-dessus de `CreateAttendeeHandler`, 1 clé d'idempotence, N Orders assumés | Schéma + Code | Besoin d'une **facture unique** par lot ? (→ C sinon) |
| **D26** | Navigation par onglets | **A** + recadrage 6 sept. : layout `Kiosk` dédié + `/kiosk/select-event` si ≥2 ; v2.1 = **Vente + Réglages pour tout le monde** (Commandes/Stats = backlog, y compris ORGANIZER) | Code moyen | — |
| **D27** | Écran Commandes | **A** (Orders natif + filtre `is_manually_created`) **pour un ORGANIZER** ; **B** (`GET /events/{id}/box-office-sales` scoped) obligatoire pour donner l'écran à l'opérateur | Code faible (A) / moyen (B) | Donner l'écran Commandes à l'opérateur en v2.1 ou plus tard ? |
| **D28** | Écran Statistiques | **A** (`GET /events/{id}/stats`) **pour un ORGANIZER** ; **B/C** (agrégat box-office scoped) pour l'opérateur, après D15 | Code faible (A) | Séquencer après D15 |
| **D29** | Réglages par poste | **A** : `localStorage` (préférences d'affichage/impression seulement — l'événement vient de l'auth) | Code faible | — |

### Ce qui est réutilisable **tel quel** (CONFIRMÉ) — pour un ORGANIZER connecté au shell

- Recherche commandes par **nom / email / n° de commande** : `GET /events/{id}/orders?query=` — `OrderRepository.php:38-52`.
- **Filtres** commandes (statut, paiement, remboursement, dates, montant) + **tri** — `OrderDomainObject.php:39-78`.
- **Export** commandes / attendees — `ExportOrdersAction`, `ExportAttendeesAction`.
- **Statistiques événement** (CA, billets, commandes, taxes, frais, **remboursements**, série journalière) — `EventStatsFetchService`, ventes guichet **déjà incluses**.
- **Check-in stats** (total / scannés) — `EventStatsFetchService::getCheckedInStats()`.
- Détail / remboursement / annulation / renvoi de confirmation d'une commande — routes `backend/routes/api.php:368-374`.
- Composants front : `OrdersTable`, `SearchBarWrapper`, `Pagination`, `FilterModal`, `useGetEventOrders`, `useGetEventStats`, `useFilterQueryParamSync`.
- Vente 1 billet + prix serveur strict + idempotence + PDF + réimpression : **slice 1**, inchangé.

⚠️ **Ces endpoints sont gâtés `ORGANIZER`** → un `BOX_OFFICE_OPERATOR` (D23 Option 1) reçoit **403**.
Pour l'opérateur, seules restent : la vente (`box-office-sales`), le PDF et la réimpression, une
fois leur autorisation basculée sur `validateBoxOfficeEventScope`.

### Ce qui demande du neuf (backend)

- Shell `/kiosk` + layout `Kiosk` + garde par host `server.js` (D22, D26).
- **D23 Option 1 (✅ validée)** : `Role::BOX_OFFICE_OPERATOR` (enum) ; migration `event_box_office_operators` (multi-événements, `unique(event_id, user_id)`) ; 2 gardes dans `IsAuthorizedService` (`validateUserRole` négative + `validateBoxOfficeEventScope`) ; `BaseAction::isBoxOfficeActionAuthorized()` ; bascule des 3 actions box-office ; `GetBoxOfficeContextAction` (liste des événements de l'opérateur) ; `Create/Get/RevokeBoxOfficeOperatorAction` **`minimumAllowedRole(ADMIN)`** + Handlers + repo ; sélecteur d'événement front si ≥2. Détail complet : parcours v2.1 §B.
- Handler + endpoint **multi-items** + migration (`box_office_sale_items` ou `cart_id`) (D25-B).
- Endpoint `GET /events/{id}/box-office-sales` scoped opérateur (D27-B) — obligatoire pour donner Commandes à l'opérateur.
- (si D15) migration `box_office_cash_sessions` + `box_office_sales.session_id` + écrans ouverture/suspension/clôture.
- (si D28-B) service d'agrégation `box-office-stats` scoped + endpoint + resource.
- (si D19b-B) machine à états paiement TPE + webhooks + réconciliation.

---

## Parcours vertical v2.1 — **définitif, pour validation finale avant implémentation**

Décisions actées intégrées : **D22-A** (sous-domaine, même bundle) · **D23 Option 1** (rôle
`BOX_OFFICE_OPERATOR`, création réservée `ADMIN`, multi-événements + sélecteur) · **D26-A** (shell à
onglets) · **D29-A** (réglages `localStorage`).

Sur le modèle de `PICHA_BOX_OFFICE_FIRST_SLICE.md` : **le plus petit parcours démontrable qui prouve
la vraie séparation** — un compte opérateur réel, distinct d'ADMIN/ORGANIZER, rattaché à un ou
plusieurs événements précis, **incapable de toucher quoi que ce soit d'autre**, vérifié au niveau
API. Hors périmètre v2.1 : panier multi-items (D25), session de caisse (D15), TPE API (D19b),
onglets Commandes/Stats **pour tout le monde** (y compris ORGANIZER ; D27/D28), stats par opérateur (D28-B).

---

### A. Parcours cible (ce que Jo verra fonctionner)

```
ADMIN (back-office app.picha.fr)
  └─ /manage/event/:eventId/box-office/operators  (écran réservé ADMIN)
       └─ « Inviter un opérateur » : prénom, nom, email
            └─ mail d'invitation Hi.Events (inchangé)

Opérateur
  └─ clic lien d'invitation → pose son mot de passe (AcceptInvitationHandler inchangé)
  └─ va sur https://kiosk.picha.fr → /kiosk/login → saisit email + mot de passe
       GET /box-office/context  →  liste de SES événements ACTIVE
         • 0 événement  → écran « Aucun événement assigné »
         • 1 événement  → redirect /kiosk/event/:id/sell
         • ≥2 événements → /kiosk/select-event (cartes) → choix → /kiosk/event/:id/sell
  └─ Shell Guichet, 2 onglets : [ Vente ] [ Réglages ]
       Vente    = page Box Office du slice 1, re-hébergée (1 billet/opération, pas de panier)
       Réglages = préférences locales (localStorage) : imprimante, langue billet par défaut,
                  send_confirmation_email par défaut, nom d'affichage du poste
  └─ « Changer d'événement » (si ≥2) → retour /kiosk/select-event, sans re-login

Contrôles négatifs (tous → 403 API) :
  POST /events/{événement NON assigné}/box-office-sales
  GET  /events/{n'importe lequel}/orders | /stats | /settings
  POST /events/{...}/orders/export
  GET  /events            (liste des événements du compte)
  POST /events/{...}/box-office/operators   (création d'opérateur — ADMIN only)
  toute route /manage/* côté front
```

Un **ORGANIZER** qui ouvre `kiosk.picha.fr` voit **les mêmes 2 onglets** (Vente + Réglages) — pas
Commandes ni Statistiques en v2.1 (backlog, même s'il a le droit API sur les endpoints natifs).

---

### B. Périmètre technique

**B.1 — Backend, compte opérateur (D23 Option 1)**

| # | Élément | Détail |
|---|---|---|
| 1 | `Role::BOX_OFFICE_OPERATOR` | ajout à l'enum `backend/app/DomainObjects/Enums/Role.php`. **Pas** dans `getAssignableRoles()`. `account_users.role` = `varchar(100)` → **aucune migration de colonne**. |
| 2 | Migration `event_box_office_operators` | `id`, `event_id` (FK `events` cascade), `user_id` (FK `users`), `created_by_user_id` (FK `users`), `status` (`ACTIVE`/`REVOKED`, défaut `ACTIVE`), `timestamps`, **`unique(event_id, user_id)`**, index `user_id`. Puis `generate-domain-objects`. |
| 3 | Repo `EventBoxOfficeOperatorRepository` | interface + impl Eloquent, `extends BaseRepository`. |
| 4 | `IsAuthorizedService::validateUserRole()` | garde négative **en tête** : `getCurrentAccountUser()?->getRole() === Role::BOX_OFFICE_OPERATOR->name` **et** `minimumRole !== Role::BOX_OFFICE_OPERATOR` → `UnauthorizedException`. Ferme les 132 endpoints. |
| 5 | `IsAuthorizedService::validateBoxOfficeEventScope(int $eventId, UserDomainObject $user)` | rôle ∈ {ADMIN, SUPERADMIN, ORGANIZER} → no-op (comportement actuel) ; `BOX_OFFICE_OPERATOR` → exiger une ligne `event_box_office_operators` `{event_id, user_id, status: ACTIVE}`, sinon `UnauthorizedException`. |
| 6 | `BaseAction::isBoxOfficeActionAuthorized(int $eventId)` | `validateUserStatus` + `event.account_id === authAccountId` + `validateBoxOfficeEventScope`. ~8 lignes. |
| 7 | Bascule d'autorisation | `CreateBoxOfficeSaleAction`, `GetBoxOfficeTicketPdfAction`, `ReprintBoxOfficeTicketAction` : `isActionAuthorized(...)` → `isBoxOfficeActionAuthorized($eventId)`. 1 ligne chacune. |
| 8 | `GetBoxOfficeContextAction` | `GET /box-office/context` (auth : opérateur **ou** ORGANIZER). Renvoie `[{ id, title, currency, timezone }]` = les événements `ACTIVE` de l'opérateur (`event_box_office_operators` JOIN `events`), ou tous les événements du compte pour un ORGANIZER (borné). |
| 9 | `CreateBoxOfficeOperatorAction` + Handler + Request | `POST /events/{event_id}/box-office/operators {first_name, last_name, email}`. **`minimumAllowedRole(Role::ADMIN)`**. Transaction : réutilise `users` existant sinon `create(password:'invited')` ; `AccountUserAssociationService::associate(..., Role::BOX_OFFICE_OPERATOR, INVITED)` si la ligne n'existe pas ; `INSERT event_box_office_operators` (409 clair si `unique(event_id, user_id)` violée) ; `SendUserInvitationService::sendInvitation` **inchangé** (seulement si `users` neuf/`INVITED`). |
| 10 | `GetBoxOfficeOperatorsAction` | `GET /events/{event_id}/box-office/operators` (**ADMIN**) → liste + statut par opérateur pour cet événement. |
| 11 | `RevokeBoxOfficeOperatorAction` | `PATCH /events/{event_id}/box-office/operators/{user_id} {status: REVOKED}` (**ADMIN**) → la ligne de **cet** événement passe `REVOKED` ; les autres affectations de l'opérateur restent. `users`/`account_users` conservés (audit). |
| 12 | Routes | `backend/routes/api.php` : +4 routes ci-dessus, dans le groupe `auth:api`. |

**B.2 — Backend, flux de vente : INCHANGÉ**

- `box_office_sales.agent_user_id` / `print_jobs.agent_user_id` : **aucune migration**. L'opérateur
  est un `users` → `getAuthenticatedUser()->getId()` renvoie un id valide.
- `CreateBoxOfficeSaleHandler`, `CreateAttendeeHandler`, `ReprintBoxOfficeTicketHandler`, DTO,
  resources : **aucune retouche**. Tests du slice 1 (`BoxOfficeEndToEndTest`, `ReprintTest`,
  idempotence, stock, prix) restent verts sans modification.

**B.3 — Frontend, shell Guichet**

| # | Élément | Détail |
|---|---|---|
| 13 | `frontend/server.js` | host `kiosk.*` : si l'URL n'est pas sous `/kiosk` → `res.redirect(302, '/kiosk')`. (≈ la logique `CUSTOM_DOMAINS` existante, sans le mapping `organizerPath`.) |
| 14 | Infra (hors dépôt, avec Jo) | `SESSION_DOMAIN=.picha.fr` ; `CORS_ALLOWED_ORIGINS` inclut `https://kiosk.picha.fr` ; DNS + vhost `kiosk.picha.fr` → conteneur `frontend`. **Le changement de `SESSION_DOMAIN` n'invalide AUCUNE session en cours** (preuve : D22 §CORS/cookie — le domaine n'est que dans l'en-tête `Set-Cookie`, pas dans le JWT ; les cookies existants restent acceptés jusqu'à expiration `JWT_TTL`/refresh). Il suffit qu'il soit posé **avant** la 1re connexion Kiosk. |
| 15 | Routes `router.tsx` | `/kiosk/login`, `/kiosk/select-event`, `/kiosk/event/:eventId/{sell,settings}`, `/kiosk/no-event`. Toutes hors de l'arbre `/manage`. |
| 16 | `components/layouts/Kiosk/` + `.module.scss` | barre d'onglets fixe (Mantine `Tabs`/`SegmentedControl` + `NavLink`). Onglets **Vente + Réglages uniquement**, pour tous les rôles (opérateur, ORGANIZER, ADMIN). Bouton « Changer d'événement » si `context.length ≥ 2`. |
| 17 | `/kiosk/login` | réutilise `authClient.login` ; après succès, `GET /box-office/context` → route selon 0 / 1 / ≥2. |
| 18 | `useGetBoxOfficeContext` (React Query) + `boxOfficeClient` étendu | nouveau. |
| 19 | Onglet **Vente** | ré-export de `components/routes/event/BoxOffice` (slice 1), `eventId` = param de route. |
| 20 | Onglet **Réglages** | `useKioskSettings` — hook `localStorage` SSR-safe (try/catch, lu au montage client). Clé `picha_kiosk_settings`. **Ne stocke jamais l'`eventId`.** |
| 21 | Écran ADMIN « Opérateurs de guichet » | `/manage/event/:eventId/box-office/operators` : liste (`GetBoxOfficeOperatorsAction`), modal « Inviter » (réutilise le patron `InviteUserModal`), action « Révoquer ». Visible seulement si le user courant est ADMIN. |

**B.4 — Exclus de v2.1 (backlog, ordre)**

| Ordre | Chantier | Décision préalable |
|---|---|---|
| 1 | Onglets Commandes et Statistiques **pour tout le monde** (ORGANIZER : endpoints natifs ; opérateur : D27-B / D28-B scoped) | recadrage 6 sept. — hors v2.1 |
| 2 | Onglet Commandes **scoped opérateur** (`GET /events/{id}/box-office-sales`) | D27-B |
| 3 | Onglet Statistiques **scoped opérateur** (agrégat box-office) | D28-B (après D15) |
| 4 | Panier multi-billets | D25 (D4 rouverte) |
| 5 | Session de caisse | D15 |
| 6 | Paiement TPE intégré via API | D19b-B (+ prestataire) |

---

### C. Critères d'acceptation mesurables (v2.1)

**C.1 — Séparation du compte opérateur (le cœur)**

- [ ] **AC-v2-1** Un `BOX_OFFICE_OPERATOR` assigné à l'événement A **crée une vente sur A** → 201, identique au slice 1 (mêmes garde-fous prix/stock/idempotence, même PDF).
- [ ] **AC-v2-2** Le même opérateur, `POST /events/{B}/box-office-sales` (B non assigné) → **403**, aucune écriture (ni `box_office_sales`, ni `orders`, ni `quantity_sold`).
- [ ] **AC-v2-3** Le même opérateur → **403** sur chacun : `GET /events/{A}/orders`, `GET /events/{A}/stats`, `GET /events/{A}/settings`, `PUT /events/{A}`, `POST /events/{A}/orders/export`, `GET /events`, `GET /users`.
- [ ] **AC-v2-4** Le même opérateur, `POST /events/{A}/box-office/operators` → **403** (création réservée ADMIN), **même s'il est assigné à A**.
- [ ] **AC-v2-5** Front : l'opérateur connecté sur `kiosk.picha.fr` n'atteint jamais le layout `Event` ni un lien Settings/Reports/Webhooks/Attendees/autres événements ; toute URL `/manage/*` tapée à la main le renvoie au shell `/kiosk` ou à `/kiosk/login`.
- [ ] **AC-v2-6** `box_office_sales.agent_user_id` de AC-v2-1 = l'`id` du `users` de l'opérateur ; `print_jobs.agent_user_id` idem après une réimpression. **Zéro migration** sur ces deux tables (vérifié : `git diff` ne touche ni `box_office_sales` ni `print_jobs`).
- [ ] **AC-v2-7** Non-régression : un `ORGANIZER` du compte crée toujours une vente sur **n'importe quel** événement de son compte (la garde `validateUserRole` ne le bloque pas) ; un `ADMIN` aussi.

**C.2 — Multi-événements & sélecteur**

- [ ] **AC-v2-8** Opérateur assigné à **2** événements A et B (2 lignes `event_box_office_operators` ACTIVE) : `GET /box-office/context` renvoie **exactement** `[A, B]` (pas les autres événements du compte).
- [ ] **AC-v2-9** À la connexion, cet opérateur atterrit sur **`/kiosk/select-event`** (2 cartes) ; le choix de A mène à `/kiosk/event/{A}/sell` ; « Changer d'événement » revient au sélecteur **sans** re-login ; il peut vendre sur B.
- [ ] **AC-v2-10** Opérateur assigné à **1** seul événement → **redirection directe** vers la vente, pas de sélecteur.
- [ ] **AC-v2-11** Opérateur dont **toutes** les affectations sont `REVOKED` → écran « Aucun événement assigné », **aucune** vente possible (`GET /box-office/context` = `[]`).
- [ ] **AC-v2-12** `unique(event_id, user_id)` : ré-inviter le même opérateur au même événement → pas de doublon (no-op ou 409 clair), pas de 2ᵉ mail.

**C.3 — Création / cycle de vie (ADMIN only)**

- [ ] **AC-v2-13** Un **ADMIN** invite un opérateur (email neuf) sur A → `users` (INVITED) + `account_users` (role `BOX_OFFICE_OPERATOR`, INVITED) + `event_box_office_operators` (A, ACTIVE) ; **1** mail d'invitation Hi.Events envoyé.
- [ ] **AC-v2-14** Un **ORGANIZER** sur le même endpoint → **403**.
- [ ] **AC-v2-15** L'opérateur accepte l'invitation (`AcceptInvitationHandler` **inchangé**) → mot de passe posé, `account_users.status → ACTIVE` ; il se connecte sur `/kiosk/login`.
- [ ] **AC-v2-16** Assigner un opérateur **existant** (déjà actif sur B) à l'événement A : ajoute **une ligne** `event_box_office_operators (A)`, **ne recrée pas** le `users`, **ne renvoie pas** de mail d'invitation (compte déjà ACTIVE).
- [ ] **AC-v2-17** Révocation sur A (`status → REVOKED`) → l'opérateur reçoit 403 sur `POST /events/{A}/box-office-sales` ; s'il est encore ACTIVE sur B, il vend toujours sur B ; les ventes passées sur A et leur `agent_user_id` sont **intactes**.
- [ ] **AC-v2-18** `Role::BOX_OFFICE_OPERATOR` **absent** de `getAssignableRoles()` et du modal d'invitation account-wide (`InviteUserModal`) ; aucune route publique d'auto-inscription.

**C.4 — Shell / sous-domaine**

- [ ] **AC-v2-19** `https://kiosk.picha.fr/` → 302 vers `/kiosk` ; `https://app.picha.fr` inchangé (back-office complet).
- [ ] **AC-v2-20** CORS : requête `withCredentials` depuis `https://kiosk.picha.fr` vers l'API → acceptée (cookie `token` envoyé et honoré) ; une origine non listée → rejetée.
- [ ] **AC-v2-21** Onglet Réglages : préférences persistées en `localStorage`, conservées au rechargement du poste ; le rendu **SSR** ne lit jamais `localStorage` (pas d'erreur d'hydratation, `try/catch` sur tous les accès).
- [ ] **AC-v2-22** Un ORGANIZER connecté sur `kiosk.picha.fr` voit **les mêmes 2 onglets** que l'opérateur (Vente + Réglages). Commandes et Statistiques **ne sont pas** dans le shell v2.1 (backlog).
- [ ] **AC-v2-23** `npx tsc --noEmit` ne régresse pas (référence T9 = 96 erreurs héritées).

**C.5 — Tests à écrire AVANT implémentation (TDD, `DatabaseTransactions`)**

`tests/Unit/BoxOffice/BoxOfficeOperatorAuthorizationTest.php`
- garde `validateUserRole` : `BOX_OFFICE_OPERATOR` + `minimumRole ORGANIZER` → exception ; + `minimumRole BOX_OFFICE_OPERATOR` → passe.
- `validateBoxOfficeEventScope` : ligne ACTIVE → passe ; `REVOKED` → exception ; absente → exception ; ORGANIZER → no-op.

`tests/Feature/BoxOffice/BoxOfficeOperatorScopeTest.php`
- AC-v2-1..4, AC-v2-7, AC-v2-22 (HTTP réels).

`tests/Feature/BoxOffice/BoxOfficeOperatorMultiEventTest.php`
- AC-v2-8..12.

`tests/Feature/BoxOffice/BoxOfficeOperatorLifecycleTest.php`
- AC-v2-13..18.

---

### D. Surface technique — récap

| Zone | Fichiers | Type |
|---|---|---|
| Enum | `Role.php` (+`BOX_OFFICE_OPERATOR`) | modif 1 ligne |
| Migration | `event_box_office_operators` (+ DO généré) | **1 nouvelle migration** |
| Repo | `EventBoxOfficeOperatorRepository` (interface + Eloquent) | nouveau |
| Autorisation | `IsAuthorizedService` (2 méthodes), `BaseAction::isBoxOfficeActionAuthorized()` | modif ~25 lignes, 2 fichiers centraux |
| Actions box-office | `CreateBoxOfficeSaleAction`, `GetBoxOfficeTicketPdfAction`, `ReprintBoxOfficeTicketAction` | modif 1 ligne chacune |
| Actions opérateur | `Get/Create/RevokeBoxOfficeOperatorAction` + Handlers + Requests, `GetBoxOfficeContextAction` | nouveau (réutilise `AccountUserAssociationService`, `SendUserInvitationService`, `AcceptInvitationHandler` — **inchangés**) |
| Routes | `routes/api.php` : +5 routes | modif |
| Flux de vente | `box_office_sales`, `print_jobs`, `CreateBoxOfficeSaleHandler`, `CreateAttendeeHandler` | **inchangés** |
| Front shell | `server.js` (garde host) ; `layouts/Kiosk/` ; routes `/kiosk/*` ; `useGetBoxOfficeContext` ; `useKioskSettings` ; ré-export `BoxOffice` | modif + nouveau |
| Front ADMIN | écran « Opérateurs de guichet » (liste + inviter + révoquer) | nouveau |
| Infra (hors dépôt) | `SESSION_DOMAIN`, `CORS_ALLOWED_ORIGINS`, DNS, vhost | Jo |
| Tests | 1 Unit + 3 Feature (voir C.5) | nouveau |

**1 seule migration DB. Aucune migration sur `box_office_sales` / `print_jobs`. Aucun changement du
flux de vente ni de `CreateAttendeeHandler` ni des flux d'invitation/reset.** Slice **additif et
`git revert`-able**. `SESSION_DOMAIN=.picha.fr` : **sans impact sur les sessions en cours**
(preuve D22 §CORS/cookie) — à poser avant la 1re connexion Kiosk, pas de fenêtre de maintenance
nécessaire.

---

### E. Définition de « prêt à développer » — état

| Prérequis | État |
|---|---|
| D23 : Option 1 | ✅ **validée** (6 sept. 2026) |
| D23 : création opérateur = ADMIN only | ✅ **validée** |
| D23 : multi-événements + sélecteur | ✅ **validée** |
| D22 : mécanisme sous-domaine (A) | ✅ validée (recommandation). `SESSION_DOMAIN` : **impact sur sessions en cours = nul** (prouvé). Reste à obtenir les valeurs infra actuelles + poser `SESSION_DOMAIN=.picha.fr` / `CORS_ALLOWED_ORIGINS` — **non bloquant pour la branche** (le front Kiosk se développe en local sur le même domaine). |
| D15 : session de caisse | ✅ **backlog confirmé (Jo, 6 sept.)** — ne pas traiter en v2.1 |
| D26 : shell à onglets, **Vente + Réglages pour tout le monde** (Commandes/Stats backlog y compris ORGANIZER) | ✅ validée + recadrage 6 sept. |
| D29 : réglages `localStorage` | ✅ validée |
| Tests de caractérisation de la garde d'autorisation écrits **avant** le code | ⬜ à faire au démarrage du slice (TDD, comme le slice 1) |
| Conteneur `docker/development` + base de test isolée | ✅ (déjà en place depuis le slice 1) |

**Bloquants restants avant `git checkout -b` : aucun côté conception.** Les valeurs infra D22
(`SESSION_DOMAIN` / `CORS_ALLOWED_ORIGINS` en place) sont à confirmer avec Jo avant la **mise en
service** du Kiosk, pas avant d'ouvrir la branche : le développement + les tests se font en local,
et le changement `SESSION_DOMAIN` est sans impact sur l'existant.
