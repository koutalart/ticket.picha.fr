# PICHA Baseline Inventory — état du working tree avant PICHA Kiosk

Date : 3 septembre 2026
Méthode : lecture seule (`git`, lecture de fichiers). Aucune modification, aucun `git add/commit/stash/clean`, aucune suppression.
Dépôt : `/opt/digit-ticket` — branche `staging`.

---

## 0. Résumé exécutif

| Question | Réponse |
|---|---|
| HEAD | `2aa3ee8d` « feat(digit): freeze Device Registry B2-B5 before Somaroho » (2026-07-18) |
| `git describe` | `DIGIT-STAGING-BRACELETS-v1.0-1-g2aa3ee8d` (1 commit au-dessus du dernier tag) |
| Tag amont | `v.1.10.0-beta` → `619cbf0a` ; **9 commits DIGIT** au-dessus |
| Fichiers suivis modifiés | **35** (`M` 34 + `D` 1) |
| Fichiers non suivis | **~90** entrées, dont ~30 `.bak`, ~20 fichiers parasites, 2 dumps SQL de 24 Mo, 1 arbre `themes/festival/`, 1 dossier `backups/` |
| Secrets exposés au commit | `frontend/.env.staging.bak-*` (2) — **non couverts par `.gitignore`** ; contenu = variables `VITE_*` uniquement (clé Stripe *publishable*, URLs) — pas de clé secrète détectée |
| Dumps de données | `backups/*.sql` (2 × ~24 Mo) et `~/Downloads`, `backups/` — **non couverts par `.gitignore`**, contiennent des données de staging |
| Risque #1 | La baseline Somaroho n'est pas figée : toute branche Kiosk créée maintenant embarque ~35 fichiers non commités + le bruit. |

**Aucune action de stabilisation n'est exécutée dans cette mission.** La procédure recommandée est en §5, à autoriser explicitement.

---

## 1. Fichiers suivis modifiés (`git diff`)

Catégories : **BRANDING** · **INFRA** · **I18N** · **SOMAROHO-CHECKOUT** · **SOMAROHO-PDF** · **DIGIT-SCAN** · **CONFIG**

| Fichier | Δ | Catégorie | Rôle prouvé par le diff | Conserver | Test nécessaire | Commit thématique proposé | Risque si retiré | Risque si inclus tel quel |
|---|---|---|---|---|---|---|---|---|
| `backend/composer.json` | +1 | SOMAROHO-PDF | Ajoute `simplesoftwareio/simple-qrcode ^4.2` | Oui | `composer validate` + build image | `feat(pdf): génération QR serveur pour le billet PDF` | PDF billet KO (QR) | Aucun — cohérent avec lock |
| `backend/composer.lock` | +174/−2 | SOMAROHO-PDF | Verrouille `simple-qrcode` + `bacon/bacon-qr-code` + `dasprid/enum`. DomPDF (`barryvdh/laravel-dompdf ^3`, `dompdf/dompdf`) **était déjà** dans le lock de base | Oui | build image + `composer install --no-dev` | idem composer.json (même commit) | build casse (lock désync) | Aucun |
| `backend/app/Mail/Attendee/AttendeeTicketMail.php` | +46/−1 | SOMAROHO-PDF | Ajoute une pièce jointe PDF au mail billet : `generateTicketPdf()` privée → `QrCode::format('png')->generate($attendee->getPublicId())` + `Pdf::loadView('attendee-ticket-pdf', …)->output()` | Oui | test d'envoi mail + rendu PDF (police, QR, logo CDN) | `feat(pdf): joindre le billet PDF au mail attendee` | plus de PDF dans les mails | échec silencieux si `gd` absent de l'image, ou vue manquante (voir non-suivis) |
| `backend/app/DomainObjects/AccountConfigurationDomainObject.php` | +1/−1 | CONFIG | `getApplicationFeeCurrency()` : `'USD'` → `config('app.default_application_fee_currency','EUR')`. **Fichier auto-généré** (CLAUDE.md interdit l'édition manuelle) | Oui, mais à régénérer proprement | `php artisan generate-domain-objects` doit reproduire ce diff, sinon corriger le template | `chore(config): devise de frais par défaut EUR` | frais calculés en USD | divergence si `generate-domain-objects` réécrit le fichier |
| `backend/config/app.php` | +4/−3 | CONFIG | Renomme `saas_stripe_application_fee_percent/fixed` → `default_application_fee_percentage/fixed`, ajoute `default_application_fee_currency` (EUR), `default_application_fee_fixed` 0 → **0.99**, `locale` → `env('APP_LOCALE','en')` | Oui | test unité calcul de frais ; vérifier qu'aucun autre code ne lit les anciennes clés (**vérifié : aucun**) | `chore(config): clés de frais par défaut + APP_LOCALE` | `AccountConfigurationDomainObject` (déjà à HEAD) lit `default_application_fee_*` qui renverrait `null` → **répare une incohérence latente de HEAD** | frais fixes par défaut passent à 0.99 (choix métier à valider) |
| `backend/lang/fr.json` | +25/−25 | I18N | 25 chaînes FR retouchées | Oui | `yarn`/artisan lint i18n | `chore(i18n): retouches FR` | régressions de libellés | Aucun |
| `backend/resources/views/vendor/mail/html/message.blade.php` | +1/−1 | BRANDING | Remplace `© … Powered by <a …>Hi.Events</a>` par `Powered by PICHA AI` | **DÉCISION REQUISE** | rendu mail | `chore(branding): pied de page mail` | — | **Licence AGPL §7(b)** : le commentaire juste au-dessus demande de conserver la mention « Powered by Hi.Events » sauf licence commerciale. À trancher avec Jo (cf. `VITE_I_HAVE_PURCHASED_A_LICENCE` dans les env). |
| `backend/Dockerfile` | +3/−1 | INFRA | `install-php-extensions … gd` + `php artisan storage:link` | Oui | build image backend | `infra(backend): extension gd + storage:link pour PDF` | PDF/QR KO (gd), assets storage KO | `storage:link` au build peut échouer si `storage/` monté après (cf. volume) — à tester avec le compose |
| `backend/Dockerfile.worker` | +3/−1 | INFRA | `… gd` + `USER www-data` (fin) | Oui | build image worker + exécution job mail | `infra(worker): gd + USER www-data` | jobs PDF KO | permissions d'écriture worker à vérifier |
| `docker-compose.staging.yml` | +7 | INFRA | Volume nommé `backend_storage` monté sur `backend`, `queue-worker`, `scheduler` (`/var/www/html/storage`) | Oui | `docker compose config` + smoke test | `infra(staging): volume partagé backend_storage` | storage non partagé entre web/worker (PDF/factures incohérents) | masque `storage:link` fait au build (le volume écrase le lien) — à vérifier |
| `frontend/Dockerfile.ssr` | +1 | INFRA | `RUN sh check-env-secrets.sh` avant build | Oui | build frontend | `infra(frontend): garde-fou secrets VITE_` | plus de garde-fou | **dépend d'un fichier non suivi** `frontend/check-env-secrets.sh` → build casse si commit partiel |
| `frontend/server.js` | +19/−4 | INFRA/DIGIT | `CUSTOM_DOMAINS = {'innocent976.yt': {organizerPath:'/events/6/innocent-event'}}` + redirection 302 + override `VITE_FRONTEND_URL` par host | Oui | test SSR multi-domaine | `feat(ssr): domaines personnalisés organisateurs` | domaine `innocent976.yt` ne redirige plus | mapping en dur (dette) — pas un secret |
| `frontend/public/favicon.ico`, `favicon.svg`, `manifest-icons/*` (10 fichiers) | bin/svg | BRANDING | Icônes PICHA remplacent celles Hi.Events | Oui | visuel | `chore(branding): favicons PICHA` | branding Hi.Events visible | Aucun |
| `frontend/public/logos/hi-events-logo-preview.html` | **D** −266 | BRANDING | Suppression d'une page de preview de logo Hi.Events | Oui (suppression volontaire) | aucun | `chore(branding): retirer preview logo Hi.Events` | — | **c'est le seul `D`** — à confirmer avec Jo avant de le matérialiser en commit |
| `frontend/src/api/order.client.ts` | +30/−8 | SOMAROHO-CHECKOUT | `withSessionIdentifier()` ajoute `?session_identifier=` (localStorage) aux appels order public (payment_intent, PUT order, await-offline-payment, invoice) | Oui | e2e checkout | `fix(checkout): propager session_identifier (Safari/cookies)` | checkout casse quand cookies tiers bloqués | **dépend du non-suivi** `frontend/src/utilites/checkoutSession.ts` |
| `frontend/src/queries/useGetOrderPublic.ts` | +11/−3 | SOMAROHO-CHECKOUT | `resolveSessionIdentifier()` : URL → localStorage fallback | Oui | e2e checkout | idem (même commit) | idem | idem dépendance non-suivie |
| `frontend/src/utilites/…` (import) | — | — | — | — | — | — | — | — |
| `frontend/src/components/common/CheckoutQuestion/index.tsx` | +22 | SOMAROHO-CHECKOUT | Ajoute `PhoneInput` + branche `case QuestionType.PHONE` | Oui | rendu formulaire | `feat(questions): input téléphone` | type PHONE non rendu | backend `QuestionTypeEnum::PHONE` **déjà commité** → cohérent |
| `frontend/src/components/forms/QuestionForm/index.tsx` | +7 | SOMAROHO-CHECKOUT | Option « Phone Number » dans l'éditeur de questions | Oui | — | idem (même commit) | organisateur ne peut créer une question PHONE | Aucun |
| `frontend/src/types.ts` | +1 | SOMAROHO-CHECKOUT | `QuestionType.PHONE = 'PHONE'` | Oui | `tsc --noEmit` | idem | compile KO | Aucun |
| `frontend/src/components/routes/product-widget/SelectProducts/index.tsx` | +4/−4 | SOMAROHO-CHECKOUT | `session_identifier` toujours dans l'URL `/checkout/...`, classes footer collant | Oui | e2e widget | `fix(widget): session_identifier + footer collant` | régression checkout embarqué | Aucun |
| `frontend/src/styles/widget/default.scss` | +15 | SOMAROHO-CHECKOUT | `.hi-footer-sticky`, `.hi-has-sticky-footer` | Oui | visuel | idem (même commit) | footer non collant | Aucun |
| `frontend/src/components/common/AttendeeCheckInTable/QrScanner.tsx` | +1/−1 | DIGIT-SCAN | `getUserMedia({video:{facingMode:'environment'}})` (caméra arrière) | Oui | test terrain | `fix(scan): caméra arrière par défaut` | scan utilise caméra frontale | Aucun |
| `frontend/src/pages/digit/ScannerDevicePage.tsx` | +10 | DIGIT-SCAN | Reset auto de la bannière après 2,5 s | Oui | test terrain | `fix(scan): reset bannière` | bannière figée | Aucun |
| `frontend/src/pages/digit/digitScanApiClient.ts` | +3 | DIGIT-SCAN | Ajout appel client scan | Oui | — | idem | — | Aucun |
| `frontend/src/router.tsx` | +8 | SOMAROHO-THEME | Route `/festival/:eventSlug` → `components/routes/festival/FestivalPage` (**non suivi**) | Oui | `tsc` + route | `feat(festival): page thème festival` | route festival KO | **dépend d'un arbre non suivi entier** (`themes/festival/`, `components/routes/festival/`) → commit partiel casse le build |
| `backend/modules/digit/src/Scan/Domain/DTO/ScanOutcomeDTO.php` | +34/−4 | DIGIT-SCAN | Ajoute `firstScannedByDevice/At/CheckInListName` au DTO de résultat de scan (module DIGIT, hors périmètre Kiosk mais présent dans staging) | Oui | tests module digit (non suivis, cf. §2) | `feat(digit-scan): métadonnées premier scan` | régression d'affichage « déjà scanné » | Aucun (rétro-compatible, params optionnels) |

### Couplages tracké ↔ non-tracké (bloquants pour un commit partiel)

1. `order.client.ts` + `useGetOrderPublic.ts` → **`frontend/src/utilites/checkoutSession.ts`** (non suivi).
2. `frontend/Dockerfile.ssr` → **`frontend/check-env-secrets.sh`** (non suivi).
3. `frontend/src/router.tsx` → **`frontend/src/components/routes/festival/`** + **`frontend/src/themes/festival/`** (arbres non suivis entiers).
4. `AttendeeTicketMail.php` → **`backend/resources/views/attendee-ticket-pdf.blade.php`** (non suivi) + extension `gd` (Dockerfiles).

→ Ces fichiers doivent entrer **dans le même commit** que leurs consommateurs.

---

## 2. Fichiers non suivis — à conserver

| Fichier / arbre | Catégorie | Rôle prouvé | Conserver | Commit thématique |
|---|---|---|---|---|
| `backend/resources/views/attendee-ticket-pdf.blade.php` | SOMAROHO-PDF | Vue Blade du billet PDF : en-tête accent, table détails, QR base64 (`$attendee->getPublicId()`), `Ticket ID`, footer. Police `DejaVu Sans` | Oui | avec `AttendeeTicketMail.php` |
| `frontend/src/utilites/checkoutSession.ts` | SOMAROHO-CHECKOUT | `getStoredSessionIdentifier` / `storeSessionIdentifier` (localStorage) | Oui | avec `order.client.ts` |
| `frontend/check-env-secrets.sh` | INFRA | Refuse le build si une clé `sk_live_/sk_test_/whsec_` est dans une var `VITE_*` | Oui | avec `Dockerfile.ssr` |
| `frontend/src/components/routes/festival/FestivalPage/index.tsx` | SOMAROHO-THEME | Page d'entrée du thème festival | Oui | commit `feat(festival)` |
| `frontend/src/themes/festival/**` (~35 fichiers : components, layouts, services, types, `events/somaroho.ts`) | SOMAROHO-THEME | Thème éditorial festival (Hero, Countdown, FAQ, Sponsors, TicketGrid…) + config `somaroho.ts` | Oui | commit `feat(festival)` |
| `frontend/public/assets/festival/somaroho/hero.jpeg` (575 Ko) | SOMAROHO-THEME | Image hero Somaroho | Oui | commit `feat(festival)` (ou LFS) |
| `frontend/public/logos/picha-*.{png,svg}` (4) + `frontend/public/favicon.svg` + `frontend/public/apple-touch-icon.png` | BRANDING | Logos PICHA | Oui | `chore(branding)` |
| `backend/modules/digit/tests/Scan/**`, `backend/modules/digit/tests/Devices/**` (4 fichiers : `AuthenticateScanDeviceMiddlewareTest`, `DeviceLifecycleServiceTest`, 2 × `InMemorySqliteTestCase`) | DIGIT-SCAN-TESTS | Tests du module DIGIT (SQLite en mémoire) | Oui | `test(digit): scan + devices` |

---

## 3. Fichiers non suivis — parasites (suppression recommandée, **après autorisation**)

### 3.1 Fragments de collage shell (≈ 20 fichiers à la racine)

Fichiers vides ou de quelques Ko dont le nom est un morceau de code PHP :
`->extractToken($request);`, `->unauthorized('Missing scan device token');`, `->unauthorized('This device is not authorized for this check-in list');`, `5,`, `ACTIVE,`, `Hash Test Device,`, `account_id`, `all_check_in_lists`, `already enforced above, at lookup`, `event_id`, `false,`, `name`, `self::EVENT_A,`, `status`, `{`, `}`, `t);`, `t->header('Authorization');`, `t->header('X-Scan-Token');`, `t_used_at' => now()]);`, `t->attributes->set('digit_scan_device', $device);`, `table. This is intentionally separate from Hi.Events'`, `trict_types=1);`, `tring $message): Response`, `ubstr($header, 7);`, `h apres modification ===`, `"t $request): ?string"`, `"t $request, Closure $next): Response"`.

- **Cause** : collage d'un fichier PHP (middleware `AuthenticateScanDeviceMiddleware`) dans le shell ; chaque `>` a créé un fichier. Les tailles se répètent (13067, 3629, 0) = mêmes contenus recopiés.
- **Effet** : aucun (hors du build, hors `.gitignore` → apparaîtraient dans `git add -A`).
- **Recommandation** : supprimer. Risque si supprimés : **nul**. Risque si inclus : pollution du dépôt, confusion.
- ⚠️ Certains noms contiennent des caractères shell — supprimer par `git clean -n` d'abord (dry-run), jamais `rm *`.

### 3.2 `FEATURES.md` (0 octet, à la racine, **suivi**)

Fichier vide déjà commité (apparaît aussi dans `git ls-files`). Hors périmètre baseline — ne pas toucher sans décision.

### 3.3 Fichiers `.bak` horodatés (~30)

`backend/*.bak-2026073x`, `frontend/**/*.bak-2026073x`, `frontend/backup-checkout-fix/`, `frontend/src/components/layouts/EventHomepage/*.bak` (×3), etc.

- **Cause** : sauvegardes manuelles avant édition (juillet 2026).
- **Effet** : aucun.
- **Recommandation** : supprimer après avoir vérifié que le contenu « courant » correspondant est bien celui voulu (diff `.bak` ↔ fichier courant pour les 3-4 fichiers sensibles : `AttendeeTicketMail.php`, `config/app.php`, `server.js`). Risque si supprimés : perte d'un historique informel — **acceptable une fois la baseline commitée**.

### 3.4 `backups/` (dossier à la racine, ~50 Mo)

| Contenu | Nature | Recommandation |
|---|---|---|
| `backups/digit-ticket-staging-20260708-1819.sql` (24 Mo) | **Dump PostgreSQL de staging** | **Ne jamais committer.** Déplacer hors du dépôt. Peut contenir emails clients, hash de mots de passe, jetons. |
| `backups/staging_backup_20260718_155954.sql` (24 Mo) | idem | idem |
| `backups/20260718_224726_footer_sticky/` … `20260807_184721_banner_timeout/` (9 dossiers) | snapshots `.tsx/.scss/.php` avant édition | supprimer après commit de la baseline |

- **`.gitignore` ne couvre pas `backups/`** → `git add -A` les inclurait. **Risque élevé.**

### 3.5 Fichiers d'environnement (`.env.staging.bak-*`)

| Fichier | Couvert par `.gitignore` ? | Contenu (clés seulement, **valeurs non reproduites**) |
|---|---|---|
| `backend/*.env.staging.bak-*` | Oui (`backend/.gitignore` : `.env.staging.*`) — *(aucun présent actuellement)* | — |
| `frontend/.env.staging.bak-20260730-081913` | **NON** | `VITE_FRONTEND_URL`, `VITE_API_URL_CLIENT`, `VITE_API_URL_SERVER`, `VITE_STRIPE_PUBLISHABLE_KEY`, `VITE_APP_NAME`, `VITE_I_HAVE_PURCHASED_A_LICENCE` |
| `frontend/.env.staging.bak-20260730-164528` | **NON** | idem + `VITE_APP_LOGO_DARK`, `VITE_APP_LOGO_LIGHT` |

- **Contrôle secrets effectué** : ces deux fichiers ne contiennent que des variables `VITE_*` (exposées au client de toute façon). La clé Stripe y est une clé *publishable* (`pk_`), pas une clé secrète. **Aucune clé `sk_`/`whsec_` détectée.**
- **Recommandation** : supprimer quand même (ne doivent pas entrer dans le dépôt) **et** ajouter `frontend/.env.staging.*` au `.gitignore` frontend ou racine. Risque si inclus : divulgation d'URLs d'infra internes + `pk_` de staging.
- **Aucune valeur de secret n'est reproduite dans ce rapport.**

### 3.6 Divers non suivis

| Fichier | Recommandation |
|---|---|
| `PICHA_BOX_OFFICE_AUDIT.md` | Conserver (livrable d'audit). Ce rapport-ci et ses frères l'accompagnent. |
| `e2e_step1_setup.php`, `etape3_association_bracelet_terrain.php` (dans `~`, hors dépôt) | hors dépôt — ne pas toucher |

---

## 4. Fichiers sans relation identifiable

Aucun. Tous les fichiers modifiés/non suivis se rattachent à l'une des catégories : BRANDING, INFRA, I18N, SOMAROHO-CHECKOUT, SOMAROHO-PDF, SOMAROHO-THEME, DIGIT-SCAN, CONFIG, PARASITE.

---

## 5. Procédure de stabilisation recommandée (à autoriser — **non exécutée**)

> Objectif : obtenir une baseline propre `digit-staging-somaroho-2026`, sans mélanger avec Kiosk. Aucune étape ci-dessous n'a été lancée.

1. **Sauvegarde externe vérifiable**
   - `git bundle create ../digit-ticket-prebaseline-$(date +%Y%m%d).bundle --all` (hors dépôt).
   - `git stash` **interdit** ici (règle). Utiliser plutôt : `git diff > ../wt-tracked.patch` et un `tar` des non-suivis utiles (`git ls-files --others --exclude-standard`).
   - Copier `backups/*.sql` vers un stockage privé, hors dépôt, puis les retirer du working tree.

2. **Contrôle des secrets**
   - `git ls-files --others --exclude-standard | xargs grep -lE 'sk_(live|test)_|whsec_|BEGIN.*PRIVATE KEY|password\s*='` (attendu : rien).
   - Ajouter au `.gitignore` : `frontend/.env.staging.*`, `backups/`, `**/*.bak-*`, `**/*.bak`.
   - `git add -A --dry-run` et relire **toute** la liste avant tout `add` réel.

3. **Suppression des parasites** (après autorisation explicite, item par item)
   - `git clean -nd` (dry-run) → revue → `git clean -fd -e '*.md'` ciblé sur les fragments de collage.
   - Supprimer les `.bak-*` après diff de contrôle sur `AttendeeTicketMail.php`, `config/app.php`, `server.js`.
   - Déplacer/supprimer `backups/`.

4. **Tests du code Somaroho** (dans le conteneur `docker/development`)
   - `docker compose -f docker-compose.dev.yml exec backend composer validate`
   - `docker compose -f docker-compose.dev.yml exec backend php artisan generate-domain-objects` → **le diff sur `AccountConfigurationDomainObject.php` doit être reproduit** (sinon corriger la source du générateur, pas le fichier).
   - `docker compose -f docker-compose.dev.yml exec backend php artisan test --testsuite=Unit`
   - Test manuel : envoi d'un mail billet → PDF présent, QR lisible, pas d'erreur `gd`.
   - `cd frontend && npx tsc --noEmit && yarn build` (SSR) — vérifie les couplages non-suivis §1.

5. **Commits thématiques** (ordre proposé, en respectant les couplages §1)
   1. `infra(backend): gd + storage:link + volume backend_storage partagé` — Dockerfiles + compose
   2. `chore(config): clés de frais par défaut EUR + APP_LOCALE` — `config/app.php` + régénération DO
   3. `feat(pdf): billet PDF + QR serveur en pièce jointe du mail attendee` — composer.json/lock + `AttendeeTicketMail.php` + `attendee-ticket-pdf.blade.php`
   4. `feat(checkout): session_identifier robuste (cookies tiers bloqués)` — `checkoutSession.ts` + `order.client.ts` + `useGetOrderPublic.ts` + `SelectProducts` + `default.scss`
   5. `feat(questions): type téléphone (frontend)` — `types.ts` + `CheckoutQuestion` + `QuestionForm`
   6. `feat(festival): thème éditorial festival + route /festival/:slug` — `router.tsx` + `themes/festival/**` + `components/routes/festival/**` + asset hero
   7. `feat(digit-scan): métadonnées premier scan + UX scanner` — `ScanOutcomeDTO.php` + `QrScanner.tsx` + `ScannerDevicePage.tsx` + `digitScanApiClient.ts` + tests module digit
   8. `chore(branding): favicons/logos PICHA, pied de page mail, retrait preview Hi.Events` — assets + `message.blade.php` + suppression `hi-events-logo-preview.html` **(après décision licence AGPL)**
   9. `infra(frontend): garde-fou check-env-secrets au build` — `Dockerfile.ssr` + `check-env-secrets.sh`
   10. `feat(ssr): domaines personnalisés organisateurs` — `server.js`

6. **Tag** — uniquement après §4 vert et revue des 10 commits :
   `git tag -a digit-staging-somaroho-2026 -m "Baseline Somaroho figée avant PICHA Kiosk"`

7. **Branche Kiosk** — seulement ensuite :
   `git switch -c feat/picha-kiosk digit-staging-somaroho-2026`

---

## 6. Autorisations nécessaires pour exécuter §5

- [ ] Autorisation de créer le `git bundle` + patch + tar de sauvegarde (lecture, écriture hors dépôt).
- [ ] Autorisation de déplacer `backups/*.sql` hors du dépôt.
- [ ] Autorisation de modifier `.gitignore` (frontend + racine).
- [ ] Autorisation de `git clean` ciblé (dry-run partagé d'abord) sur les fragments de collage.
- [ ] Autorisation de supprimer les `.bak-*` après diffs de contrôle.
- [ ] Confirmation de la suppression volontaire de `frontend/public/logos/hi-events-logo-preview.html`.
- [ ] **Décision licence** : conserver ou retirer « Powered by Hi.Events » (AGPL §7(b)).
- [ ] Autorisation de créer les 10 commits thématiques et le tag `digit-staging-somaroho-2026`.
