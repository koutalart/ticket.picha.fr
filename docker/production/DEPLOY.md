# Déploiement PICHA Ticket — https://ticket.picha.fr

VPS OVH `vps-2570be55.vps.ovh.net` (51.210.5.75), alias SSH `cathy-vps`.
Le serveur héberge aussi Cathy, innocent976 et le staging PICHA : **ne toucher qu'aux éléments `picha-ticket-prod`**.

Architecture : nginx du VPS (HTTPS) → `/api/` → backend `127.0.0.1:8081`, `/` → frontend `127.0.0.1:3001`.
Conteneurs du projet Docker `picha-ticket-prod` : postgres, redis, backend, queue-worker, scheduler, frontend.

## 1. DNS (OVH, zone picha.fr)
Enregistrement **A** : `ticket` → `51.210.5.75`. Vérifier : `dig +short ticket.picha.fr`.

## 2. Resend
Domaine `picha.fr` vérifié (enregistrements SPF/DKIM ajoutés chez OVH), clé API → `MAIL_PASSWORD`.

## 3. Code
```bash
sudo mkdir -p /opt/picha-ticket && sudo chown ubuntu /opt/picha-ticket
git clone git@github-digit-ticket:koutalart/ticket.picha.fr.git /opt/picha-ticket
cd /opt/picha-ticket && git checkout <branche à publier>
```

## 4. Secrets (jamais commités)
```bash
cp docker/production/backend.env.production.example backend/.env.production
cp docker/production/frontend.env.production.example frontend/.env.production
nano backend/.env.production frontend/.env.production        # remplir CHANGEZ-MOI
echo "POSTGRES_PASSWORD=<même mot de passe que DATABASE_URL>" > docker/production/.env
```

## 5. Lancement
```bash
cd /opt/picha-ticket
docker compose -p picha-ticket-prod --env-file docker/production/.env \
  -f docker/production/docker-compose.prod.yml up -d --build
docker compose -p picha-ticket-prod -f docker/production/docker-compose.prod.yml exec backend php artisan migrate --force
curl -I http://127.0.0.1:3001 && curl -I http://127.0.0.1:8081/public/events/1
```

## 6. Frais de plateforme (une fois, après la 1re migration)
La configuration par défaut est créée à 0 USD : la régler à 0,99 EUR fixe par billet payant.
```bash
docker compose -p picha-ticket-prod -f docker/production/docker-compose.prod.yml exec -T backend php artisan tinker --execute \
  "DB::table('account_configuration')->where('is_system_default', true)->update(['application_fees' => json_encode(['percentage' => 0, 'fixed' => 0.99, 'currency' => 'EUR'])]);"
```
Prélevés automatiquement uniquement sur les paiements carte Stripe Connect ; ventes guichet, hors ligne
et préventes physiques : à facturer aux organisateurs à partir des rapports.

## 7. nginx + HTTPS
```bash
sudo cp docker/production/nginx/ticket.picha.fr.conf /etc/nginx/sites-available/ticket.picha.fr.conf
sudo ln -s /etc/nginx/sites-available/ticket.picha.fr.conf /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
sudo certbot --nginx -d ticket.picha.fr
```

## 8. Sauvegardes

Chaque nuit, `backup.sh` sauvegarde la base et les fichiers téléversés dans
`/var/backups/picha-ticket` (14 jours). Il envoie ensuite une **copie chiffrée hors
du serveur** (30 jours) et prévient en cas d'échec.

```bash
sudo crontab -e
0 3 * * * /opt/picha-ticket/docker/production/backup.sh >> /var/log/picha-backup.log 2>&1
```

### Copie hors du serveur (OVH Object Storage), une fois
1. **Espace de stockage** : OVH Public Cloud → Object Storage → créer un conteneur S3
   `picha-ticket-backups`, **dans une autre région que le VPS**. Créer un utilisateur S3
   et noter sa clé d'accès, sa clé secrète, l'endpoint et la région
   (par exemple `https://s3.gra.io.cloud.ovh.net`, région `gra`).
2. **Deux phrases secrètes de chiffrement** : les générer et les ranger dans un gestionnaire de mots de
   passe, **hors du VPS**. Sans elles, les sauvegardes sont illisibles, même pour nous.
   ```bash
   openssl rand -base64 32   # phrase 1
   openssl rand -base64 32   # phrase 2 (sel)
   ```
3. **Configuration rclone sur le VPS** (rclone tourne dans Docker, rien à installer) :
   ```bash
   sudo mkdir -p /etc/picha-ticket/rclone && sudo chmod 700 /etc/picha-ticket
   RCLONE="sudo docker run --rm -v /etc/picha-ticket/rclone:/config/rclone rclone/rclone:1"
   $RCLONE config create ovh s3 provider=Other env_auth=false \
       access_key_id=CLE_ACCES secret_access_key=CLE_SECRETE \
       endpoint=https://s3.gra.io.cloud.ovh.net region=gra
   $RCLONE config create picha-offsite crypt remote=ovh:picha-ticket-backups \
       password=$($RCLONE obscure 'PHRASE_1') password2=$($RCLONE obscure 'PHRASE_2')
   ```
4. **Alerte** : créer un check gratuit sur https://healthchecks.io (période 1 jour, tolérance
   2 h) et y mettre son e-mail. Noter l'URL de ping.
5. **`/etc/picha-ticket/backup.env`** (`sudo chmod 600`) :
   ```bash
   OFFSITE_REMOTE=picha-offsite:
   OFFSITE_RETENTION_DAYS=30
   HEALTHCHECK_URL=https://hc-ping.com/xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx
   ```
6. Tester : `sudo /opt/picha-ticket/docker/production/backup.sh`. La dernière ligne doit dire
   « copie chiffrée hors serveur OK », et healthchecks.io passer au vert.

### Restaurer
```bash
mkdir -p /tmp/restore
RCLONE="sudo docker run --rm -v /etc/picha-ticket/rclone:/config/rclone -v /tmp/restore:/data rclone/rclone:1"
$RCLONE lsf picha-offsite:                                  # liste des sauvegardes
$RCLONE copy picha-offsite: /data --include 'picha-ticket-20261010-*'
# Base (dans une base de test d'abord, jamais directement sur la production) :
docker compose -p picha-ticket-prod -f docker/production/docker-compose.prod.yml \
    exec -T postgres createdb -U picha_ticket picha_ticket_restore
gzip -dc /tmp/restore/picha-ticket-AAAAMMJJ-HHMMSS.sql.gz | docker compose -p picha-ticket-prod \
    -f docker/production/docker-compose.prod.yml exec -T postgres psql -U picha_ticket -d picha_ticket_restore
# Fichiers :
docker compose -p picha-ticket-prod -f docker/production/docker-compose.prod.yml exec -T backend \
    tar -xzf - -C /var/www/html/storage < /tmp/restore/picha-ticket-files-AAAAMMJJ-HHMMSS.tar.gz
```
Faire un essai de restauration une fois par trimestre : une sauvegarde jamais relue n'est pas une sauvegarde.

## Domaines personnalisés d'organisateurs
Un organisateur peut avoir son propre domaine (ex. `innocent976.yt`) : sa page d'accueil y est servie sur `/`,
ainsi que ses pages d'événement et de commande. `/manage`, `/auth`, `/admin`… renvoient vers ticket.picha.fr.

1. **Admin › Comptes › (compte) › Organisateurs et domaines** : saisir le domaine de l'organisateur.
2. **DNS** chez le registrar : enregistrements **A** du domaine et de `www` → `51.210.5.75`.
3. **HTTPS** sur le VPS :
```bash
sudo /opt/picha-ticket/docker/production/add-custom-domain.sh innocent976.yt
```
Le frontend résout le domaine via l'API (cache de 60 s) : aucun redéploiement n'est nécessaire.
Pour retirer un domaine : le vider dans l'admin, puis supprimer `/etc/nginx/sites-enabled/custom-<domaine>.conf`.
Sans cookie ni langue de navigateur (robots d'indexation), les domaines personnalisés sont servis en français.

Domaines en service :
- `innocent976.yt` → organisateur n°4 « Innocent Event » (compte 1), template Affiche, depuis le 01/10/2026.
  L'ancienne config pointant vers le staging (supprimé) est conservée dans
  `/etc/nginx/sites-available/innocent976.yt.conf.staging-backup-20261001`.

## Mise à jour

### Depuis GitHub (recommandé)
Actions → **Déployer en production** → *Run workflow* → branche (`staging` par défaut).
Le workflow refuse de déployer si la CI n'est pas verte, puis lance `deploy.sh` sur le VPS :
sauvegarde, `git pull`, reconstruction des conteneurs, migrations, vérification que
le site répond en 200 et l'API sans erreur serveur.

Mise en place (une fois) :
1. Sur le VPS, avec l'utilisateur qui possède `/opt/picha-ticket` et appartient au groupe `docker` :
   ```bash
   ssh-keygen -t ed25519 -N "" -C "github-deploy-picha" -f ~/.ssh/github_deploy
   cat ~/.ssh/github_deploy.pub >> ~/.ssh/authorized_keys
   cat ~/.ssh/github_deploy          # clé privée → secret PROD_SSH_KEY
   ssh-keyscan -t ed25519 51.210.5.75  # → secret PROD_SSH_KNOWN_HOSTS
   ```
2. GitHub → Settings → Secrets and variables → Actions → *New repository secret* :
   `PROD_SSH_HOST` (51.210.5.75), `PROD_SSH_USER`, `PROD_SSH_KEY`, `PROD_SSH_KNOWN_HOSTS`.
3. Optionnel : Settings → Environments → `production` → *Required reviewers* pour exiger
   une validation avant chaque déploiement.

### À la main (sur le VPS)
```bash
sh /opt/picha-ticket/docker/production/deploy.sh staging
```

## Mémoire (VPS 4 Go)
Production (~2,3 Go de limites) + staging (~2,3 Go) + Cathy dépassent la RAM : arrêter le staging
(`docker compose -f docker-compose.staging.yml stop` dans /opt/digit-ticket, données conservées)
une fois la production validée, ou passer en VPS-2 dès que le stock SBG6 revient.

## Impression Zebra
Depuis le VPS, l'impression ZPL envoyée par le serveur n'atteint pas l'imprimante du lieu : le guichet
propose alors le PDF. Solution cible pour les tablettes Android : Zebra Browser Print (impression locale).
