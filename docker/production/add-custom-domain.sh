#!/usr/bin/env bash
# Active un domaine personnalisé d'organisateur sur le VPS (nginx + certificat Let's Encrypt).
# Prérequis : le domaine est enregistré dans Admin > Comptes > Organisateurs, et ses enregistrements A
# (domaine et www) pointent vers ce serveur.
# Usage : sudo docker/production/add-custom-domain.sh innocent976.yt [email-certbot]
set -euo pipefail

DOMAIN="${1:?Usage: $0 <domaine> [email]}"
EMAIL="${2:-contact@picha.fr}"
DOMAIN="$(echo "$DOMAIN" | tr '[:upper:]' '[:lower:]' | sed -E 's#^https?://##; s#/.*$##; s#^www\.##')"
SERVER_IP="$(curl -4 -s https://ifconfig.me || true)"
CONF="/etc/nginx/sites-available/custom-${DOMAIN}.conf"

for host in "$DOMAIN" "www.$DOMAIN"; do
    resolved="$(dig +short A "$host" | tail -n1)"
    if [[ -n "$SERVER_IP" && "$resolved" != "$SERVER_IP" ]]; then
        echo "✗ $host pointe vers '${resolved:-rien}', attendu $SERVER_IP. Corriger le DNS puis relancer." >&2
        exit 1
    fi
done

cat > "$CONF" <<NGINX
# Domaine personnalisé organisateur — généré par add-custom-domain.sh
server {
    listen 80;
    listen [::]:80;
    server_name ${DOMAIN} www.${DOMAIN};

    client_max_body_size 20M;

    location /api/ {
        proxy_pass http://127.0.0.1:8081/;
        proxy_http_version 1.1;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_read_timeout 120s;
    }

    location / {
        proxy_pass http://127.0.0.1:3001;
        proxy_http_version 1.1;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_read_timeout 120s;
    }
}
NGINX

ln -sf "$CONF" "/etc/nginx/sites-enabled/custom-${DOMAIN}.conf"
nginx -t
systemctl reload nginx
certbot --nginx --non-interactive --agree-tos --redirect -m "$EMAIL" -d "$DOMAIN" -d "www.$DOMAIN"

echo "✓ https://$DOMAIN est servi par PICHA Ticket (production)."
