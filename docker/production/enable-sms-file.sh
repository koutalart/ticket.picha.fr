#!/usr/bin/env bash
# Branche PICHA Ticket sur la file centrale des SMS (picha-sms-file) en production.
# Recopie FILE_SMS_KEY de /opt/picha-sms/secrets/file-sms.env vers backend/.env.production (SMS_FILE_KEY)
# sans jamais l'afficher, après une copie de sauvegarde du .env, puis relance backend, queue-worker et scheduler.
# A lancer sur le serveur, apres la mise en ligne de la PR et le migrate :
#   bash /opt/picha-ticket/docker/production/enable-sms-file.sh
# Retour arriere : remettre SMS_FILE_ENABLED=false dans backend/.env.production et relancer les 3 services.
set -euo pipefail

APP_DIR="${APP_DIR:-/opt/picha-ticket}"
ENV_FILE="$APP_DIR/backend/.env.production"
SECRET_FILE="${SECRET_FILE:-/opt/picha-sms/secrets/file-sms.env}"
COMPOSE=(docker compose -p picha-ticket-prod -f "$APP_DIR/docker/production/docker-compose.prod.yml")
FILE_URL="${FILE_URL:-http://172.22.0.1:8097}"

[[ -f "$ENV_FILE" ]] || { echo "Fichier absent : $ENV_FILE" >&2; exit 1; }
[[ -r "$SECRET_FILE" ]] || { echo "Fichier illisible : $SECRET_FILE" >&2; exit 1; }

KEY="$(grep -E '^FILE_SMS_KEY=' "$SECRET_FILE" | tail -n1 | cut -d= -f2- | tr -d '\r' | sed -E 's/^["'\'']//; s/["'\'']$//')"
[[ -n "$KEY" ]] || { echo "FILE_SMS_KEY vide ou absente dans $SECRET_FILE" >&2; exit 1; }

BACKUP="$ENV_FILE.bak-$(date +%Y%m%d-%H%M%S)"
cp -p "$ENV_FILE" "$BACKUP"
chmod 600 "$BACKUP"
echo "Sauvegarde : $BACKUP"

set_var() {
    local name="$1" value="$2" tmp
    tmp="$(mktemp "$ENV_FILE.XXXXXX")"
    grep -vE "^${name}=" "$ENV_FILE" > "$tmp" || true
    printf '%s=%s\n' "$name" "$value" >> "$tmp"
    chmod --reference="$ENV_FILE" "$tmp"
    chown --reference="$ENV_FILE" "$tmp" 2>/dev/null || true
    mv "$tmp" "$ENV_FILE"
}

set_var SMS_FILE_KEY "$KEY"
unset KEY
set_var SMS_FILE_URL "$FILE_URL"
set_var SMS_FILE_ENABLED true

for name in SMS_FILE_KEY SMS_FILE_URL SMS_FILE_ENABLED; do
    printf '%s : %s ligne(s)\n' "$name" "$(grep -c "^${name}=" "$ENV_FILE")"
done

"${COMPOSE[@]}" up -d --no-deps backend queue-worker scheduler

sleep 5
"${COMPOSE[@]}" exec -T backend php -r '
    $ok = @file_get_contents(getenv("SMS_FILE_URL")."/sante");
    echo "File centrale joignable depuis le backend : ".($ok ? "oui" : "NON")."\n";
    echo "SMS_FILE_ENABLED=".getenv("SMS_FILE_ENABLED").", cle presente : ".(getenv("SMS_FILE_KEY") !== false && getenv("SMS_FILE_KEY") !== "" ? "oui" : "NON")."\n";
'
