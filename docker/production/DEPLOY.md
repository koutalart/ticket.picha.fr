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
```bash
sudo crontab -e
0 3 * * * /opt/picha-ticket/docker/production/backup.sh >> /var/log/picha-backup.log 2>&1
```

## Mise à jour
```bash
cd /opt/picha-ticket && git pull
docker compose -p picha-ticket-prod --env-file docker/production/.env \
  -f docker/production/docker-compose.prod.yml up -d --build
docker compose -p picha-ticket-prod -f docker/production/docker-compose.prod.yml exec backend php artisan migrate --force
docker builder prune -f   # cache de build uniquement, pour préserver le disque
```

## Mémoire (VPS 4 Go)
Production (~2,3 Go de limites) + staging (~2,3 Go) + Cathy dépassent la RAM : arrêter le staging
(`docker compose -f docker-compose.staging.yml stop` dans /opt/digit-ticket, données conservées)
une fois la production validée, ou passer en VPS-2 dès que le stock SBG6 revient.

## Impression Zebra
Depuis le VPS, l'impression ZPL envoyée par le serveur n'atteint pas l'imprimante du lieu : le guichet
propose alors le PDF. Solution cible pour les tablettes Android : Zebra Browser Print (impression locale).
