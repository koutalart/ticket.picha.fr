# Déploiement PICHA Ticket sur le VPS OVH (51.255.40.188)

Architecture : `nginx` du VPS (HTTPS Let's Encrypt) → `127.0.0.1:8123` → conteneur `app`
(frontend + backend + file d'attente + tâches planifiées) + `postgres` + `redis`.

## 1. DNS (espace client OVH)
Zone DNS de `picha.fr` → ajouter un enregistrement **A** : sous-domaine `ticket` → `51.255.40.188`.
Vérifier : `dig +short ticket.picha.fr` doit renvoyer `51.255.40.188`.

## 2. Resend (e-mails)
1. Resend → Domains → ajouter `picha.fr`.
2. Copier les enregistrements DNS proposés (SPF/DKIM, MX de retour) dans la zone OVH.
3. Attendre « Verified », puis créer une clé API → `MAIL_PASSWORD` du `.env`.

## 3. Préparer le VPS (une seule fois)
```bash
sudo apt update && sudo apt install -y docker.io docker-compose-v2 certbot python3-certbot-nginx git
sudo usermod -aG docker $USER   # puis se reconnecter
sudo mkdir -p /opt/picha-ticket && sudo chown $USER /opt/picha-ticket
git clone git@github.com:koutalart/ticket.picha.fr.git /opt/picha-ticket
```

## 4. Configurer
```bash
cd /opt/picha-ticket/docker/production
cp .env.production.example .env
nano .env   # remplir toutes les valeurs CHANGEZ-MOI
```

## 5. Lancer l'application
```bash
docker compose -f docker-compose.prod.yml up -d --build
docker compose -f docker-compose.prod.yml logs -f app   # migrations puis démarrage
curl -I http://127.0.0.1:8123                             # doit répondre 200
```

## 6. nginx + HTTPS
```bash
sudo cp nginx/ticket.picha.fr.conf /etc/nginx/sites-available/ticket.picha.fr
sudo ln -s /etc/nginx/sites-available/ticket.picha.fr /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
sudo certbot --nginx -d ticket.picha.fr
```

## 7. Sauvegardes
```bash
sudo crontab -e
# ajouter :
0 3 * * * /opt/picha-ticket/docker/production/backup.sh >> /var/log/picha-backup.log 2>&1
```

## Mise à jour
```bash
cd /opt/picha-ticket && git pull
cd docker/production && docker compose -f docker-compose.prod.yml up -d --build
```

## Limite connue : impression Zebra
L'impression ZPL est envoyée **par le serveur** vers l'IP de l'imprimante. Depuis le VPS,
une imprimante sur le réseau privé d'un lieu (192.168.x.x) est injoignable : le guichet
bascule alors sur le PDF. Une solution d'impression locale reste à mettre en place.
