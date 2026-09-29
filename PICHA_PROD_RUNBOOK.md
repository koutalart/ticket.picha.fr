# PICHA — mise en production sur ticket.picha.fr

Décision (Jo, 29 sept. 2026) : **option B**. Une production distincte tourne **à côté** du staging,
sur le même VPS, avec une **base neuve**. Le staging (`staging.ticket.picha.fr` /
`api-staging.ticket.picha.fr`) reste en place pour les tests.

| | Staging (inchangé) | Production (nouveau) |
|---|---|---|
| Dossier | `/opt/digit-ticket` | `/opt/picha-prod` (clone séparé) |
| Compose | `docker-compose.staging.yml` | `docker-compose.prod.yml` (projet `picha-prod`) |
| Front | `staging.ticket.picha.fr` → `127.0.0.1:3000` | `ticket.picha.fr` → `127.0.0.1:3001` |
| API | `api-staging.ticket.picha.fr` → `127.0.0.1:8080` | `api.ticket.picha.fr` → `127.0.0.1:8081` |
| Base | `digit_ticket_staging` | `picha_ticket_prod` (volume `picha-prod_pgdata`) |
| Env | `backend/.env.staging`, `frontend/.env.staging` | `backend/.env.production`, `frontend/.env.prod`, `.env` racine |
| Stripe | clés `test` | clés `live` |

**Pourquoi un dossier séparé :** `docker-compose.staging.yml` n'a pas de `name:`. Son projet Docker
porte donc le nom du dossier. Lancer la prod depuis `/opt/digit-ticket` avec un nom de projet mal
réglé recréerait les conteneurs du staging. Le clone séparé et `name: picha-prod` évitent ce
risque, et permettent de déployer en prod une version (tag) différente du staging.

**Pourquoi `frontend/.env.prod` et pas `.env.production` :** `yarn build` (mode production) lit
automatiquement `frontend/.env.production`. Ce fichier serait alors intégré dans **toute** image
construite depuis ce dossier. Avec `.env.prod`, les valeurs ne passent que par `env_file` au
démarrage, comme pour le staging.

Aucune commande de ce document n'a encore été lancée.

---

## 0. Prérequis (à vérifier avant de commencer)

- [ ] **Ce qui tourne aujourd'hui sur `ticket.picha.fr`.** `dig +short ticket.picha.fr` et
      `dig +short api.ticket.picha.fr` doivent pointer vers l'IP du VPS. Si le domaine sert
      déjà autre chose (site vitrine…), décider de son sort **avant** de basculer le DNS.
- [ ] **Mémoire du VPS.** `free -h` : la prod ajoute ≈ 2,3 Go de limites (`mem_limit`),
      autant que le staging.
- [ ] **Espace disque.** `df -h /var/lib/docker` : prévoir de la place pour les images, la base
      et les sauvegardes.
- [ ] **Compte Stripe live activé** (clés `pk_live_` / `sk_live_`).
- [ ] **SMTP de prod** et adresse d'expédition vérifiée (SPF/DKIM du domaine d'envoi).
- [ ] **Version à déployer.** La prod part d'un **tag**, pas d'une branche. Le travail Kiosk /
      Zebra n'est **pas encore dans `staging`** : il n'ira en prod qu'après son merge et sa
      validation sur le staging.
- [ ] **Les fichiers de prod présents dans le tag** (`docker-compose.prod.yml`,
      `infra/nginx/*.ticket.picha.fr.conf`, `infra/env/*`, `scripts/prod-db-backup.sh`). Ils
      doivent être mergés dans `staging` avant de poser le tag.

## 1. Code

```bash
sudo git clone git@github.com:koutalart/ticket.picha.fr.git /opt/picha-prod
cd /opt/picha-prod
git checkout <tag-de-release>      # ex. picha-prod-2026-10-01
```

## 2. Secrets (jamais commités)

```bash
cd /opt/picha-prod
umask 077
printf 'PROD_POSTGRES_PASSWORD=%s\n' "$(openssl rand -hex 32)" > .env

cp infra/env/backend.env.production.example backend/.env.production
cp infra/env/frontend.env.prod.example frontend/.env.prod
```

Remplir les deux fichiers en partant des `.env.staging` du staging (méthode décrite en tête de
chaque modèle). Points à ne pas rater :

- `DB_PASSWORD` = la valeur `PROD_POSTGRES_PASSWORD` du `.env` racine ;
- `APP_KEY` et `JWT_SECRET` **neufs**, jamais ceux du staging (générés à l'étape 3) ;
- clés Stripe **live** ;
- `DIGIT_SCAN_STAGING_MODE=false` ;
- `APP_DISABLE_REGISTRATION=false` **le temps de créer le premier compte** (étape 5).

## 3. Build et base

```bash
cd /opt/picha-prod
docker compose -f docker-compose.prod.yml build

# Générer les secrets applicatifs, puis les recopier dans backend/.env.production
docker compose -f docker-compose.prod.yml run --rm --no-deps backend php artisan key:generate --show
docker compose -f docker-compose.prod.yml run --rm --no-deps backend php artisan jwt:secret --show

docker compose -f docker-compose.prod.yml up -d postgres redis
docker compose -f docker-compose.prod.yml run --rm backend php artisan migrate --force
docker compose -f docker-compose.prod.yml up -d
docker compose -f docker-compose.prod.yml ps
```

Vérifier que le staging n'a pas bougé :
`docker compose -f /opt/digit-ticket/docker-compose.staging.yml ps` (mêmes conteneurs, même
uptime).

## 4. nginx et HTTPS

```bash
sudo cp /opt/picha-prod/infra/nginx/ticket.picha.fr.conf /etc/nginx/sites-available/
sudo cp /opt/picha-prod/infra/nginx/api.ticket.picha.fr.conf /etc/nginx/sites-available/
sudo ln -s /etc/nginx/sites-available/ticket.picha.fr.conf /etc/nginx/sites-enabled/
sudo ln -s /etc/nginx/sites-available/api.ticket.picha.fr.conf /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
sudo certbot --nginx -d ticket.picha.fr -d api.ticket.picha.fr
```

Adapter les chemins `sites-available` / `sites-enabled` (ou `conf.d`) à ceux utilisés pour le staging.

## 5. Premier compte et fermeture des inscriptions

1. Ouvrir `https://ticket.picha.fr/auth/register` et créer le compte admin PICHA.
2. Le promouvoir super-admin :
   ```bash
   docker compose -f docker-compose.prod.yml exec backend php artisan tinker --execute="echo HiEvents\Models\User::where('email','<email>')->value('id');"
   docker compose -f docker-compose.prod.yml exec backend php artisan user:make-superadmin <id>
   ```
3. Si les organisateurs ne doivent pas s'inscrire seuls : `APP_DISABLE_REGISTRATION=true` dans
   `backend/.env.production`, puis
   `docker compose -f docker-compose.prod.yml up -d backend queue-worker scheduler`.

## 6. Stripe

Dans le tableau de bord Stripe (mode **live**), créer le webhook :
`https://api.ticket.picha.fr/public/webhooks/stripe`, avec les mêmes événements que le webhook
du staging. Recopier son `whsec_…` dans `STRIPE_WEBHOOK_SECRET`, puis redémarrer `backend` et
`queue-worker`.

## 7. Recette (smoke test)

- [ ] `https://ticket.picha.fr` s'affiche, HTTPS valide ; `https://api.ticket.picha.fr` répond.
- [ ] Connexion admin, création d'un organisateur et d'un événement de test.
- [ ] Achat réel de petit montant en carte live, puis remboursement : l'e-mail de confirmation
      arrive, le PDF du billet s'ouvre, le webhook Stripe apparaît « réussi ».
- [ ] Scan du billet (check-in).
- [ ] Téléversement d'image (logo) : vérifie le volume `backend_storage` et `APP_CDN_URL`.
- [ ] Aucun lien dans les e-mails ne pointe vers `staging.` ou `api-staging.`.
- [ ] Supprimer ensuite l'événement de test.

## 8. Sauvegardes

```bash
sudo crontab -e
# 03:15 chaque nuit, 14 jours de rétention
15 3 * * * /opt/picha-prod/scripts/prod-db-backup.sh >> /var/log/picha-prod-backup.log 2>&1
```

Tester une restauration une fois, dans une base jetable, **avant** le premier vrai événement.
Copier régulièrement `/opt/picha-prod-backups` hors du VPS.

## 9. Mises à jour ultérieures

```bash
cd /opt/picha-prod
/opt/picha-prod/scripts/prod-db-backup.sh
git fetch --tags && git checkout <nouveau-tag>
docker compose -f docker-compose.prod.yml build
docker compose -f docker-compose.prod.yml run --rm backend php artisan migrate --force
docker compose -f docker-compose.prod.yml up -d
```

Toujours valider le tag sur le staging avant.

## 10. Retour arrière

- **Code :** `git checkout <tag-précédent>`, puis `build` et `up -d`. Si le nouveau tag contenait
  une migration, restaurer la sauvegarde prise juste avant la mise à jour.
- **Tout couper :** `docker compose -f docker-compose.prod.yml down` (**sans** `-v`, qui
  supprimerait la base), puis désactiver les deux sites nginx. Le staging n'est pas touché.
