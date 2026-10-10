#!/bin/bash
# Sauvegarde quotidienne de PICHA Ticket (production). Cron sur le VPS :
#   0 3 * * * /opt/picha-ticket/docker/production/backup.sh >> /var/log/picha-backup.log 2>&1
#
# 1. Dump de la base + archive des fichiers téléversés dans $BACKUP_DIR (14 jours).
# 2. Si OFFSITE_REMOTE est défini : copie chiffrée hors du serveur avec rclone
#    (remote « crypt » : le stockage ne voit jamais les données en clair),
#    rétention OFFSITE_RETENTION_DAYS jours.
# 3. Si HEALTHCHECK_URL est défini : signal de réussite ou d'échec
#    (healthchecks.io prévient par e-mail si aucune sauvegarde n'arrive).
#
# Configuration, hors du dépôt : /etc/picha-ticket/backup.env (voir DEPLOY.md).
set -euo pipefail

CONFIG_FILE="${PICHA_BACKUP_CONFIG:-/etc/picha-ticket/backup.env}"
if [ -f "$CONFIG_FILE" ]; then
    # shellcheck source=/dev/null
    . "$CONFIG_FILE"
fi

BACKUP_DIR="${BACKUP_DIR:-/var/backups/picha-ticket}"
RETENTION_DAYS="${RETENTION_DAYS:-14}"
OFFSITE_REMOTE="${OFFSITE_REMOTE:-}"
OFFSITE_RETENTION_DAYS="${OFFSITE_RETENTION_DAYS:-30}"
RCLONE_CONFIG_DIR="${RCLONE_CONFIG_DIR:-/etc/picha-ticket/rclone}"
HEALTHCHECK_URL="${HEALTHCHECK_URL:-}"

ping_healthcheck() {
    if [ -n "$HEALTHCHECK_URL" ]; then
        curl -fsS -m 10 --retry 3 "$HEALTHCHECK_URL$1" > /dev/null || true
    fi
}

on_error() {
    echo "$(date -Iseconds) ERREUR : sauvegarde échouée (ligne $1)" >&2
    rm -f "$BACKUP_DIR"/picha-ticket-*.part
    ping_healthcheck /fail
}
trap 'on_error $LINENO' ERR

cd "${PICHA_DIR:-/opt/picha-ticket}"
mkdir -p "$BACKUP_DIR"
ping_healthcheck /start

STAMP=$(date +%Y%m%d-%H%M%S)
COMPOSE="docker compose -p picha-ticket-prod -f docker/production/docker-compose.prod.yml"

# Écrit dans un fichier temporaire puis renomme : jamais de sauvegarde tronquée
# qui aurait l'air valide.
DB_FILE="$BACKUP_DIR/picha-ticket-$STAMP.sql.gz"
$COMPOSE exec -T postgres pg_dump -U picha_ticket -d picha_ticket --no-owner | gzip > "$DB_FILE.part"
gzip -t "$DB_FILE.part"
if [ "$(gzip -dc "$DB_FILE.part" 2>/dev/null | head -c 4096 | wc -c || true)" -lt 100 ]; then
    echo "ERREUR : dump de la base vide" >&2
    false
fi
mv "$DB_FILE.part" "$DB_FILE"

# Fichiers téléversés (logos, couvertures, sponsors) : volume backend_storage.
FILES_FILE="$BACKUP_DIR/picha-ticket-files-$STAMP.tar.gz"
$COMPOSE exec -T backend tar -czf - -C /var/www/html/storage app > "$FILES_FILE.part"
gzip -t "$FILES_FILE.part"
mv "$FILES_FILE.part" "$FILES_FILE"

find "$BACKUP_DIR" -name 'picha-ticket-*.gz' -mtime +"$RETENTION_DAYS" -delete
find "$BACKUP_DIR" -name 'picha-ticket-*.part' -delete

if [ -n "$OFFSITE_REMOTE" ]; then
    RCLONE="docker run --rm -v $RCLONE_CONFIG_DIR:/config/rclone -v $BACKUP_DIR:/data:ro rclone/rclone:1"
    $RCLONE copy /data "$OFFSITE_REMOTE" \
        --include "picha-ticket-$STAMP.sql.gz" --include "picha-ticket-files-$STAMP.tar.gz"
    $RCLONE cryptcheck /data "$OFFSITE_REMOTE" --one-way \
        --include "picha-ticket-$STAMP.sql.gz" --include "picha-ticket-files-$STAMP.tar.gz"
    $RCLONE delete "$OFFSITE_REMOTE" --min-age "${OFFSITE_RETENTION_DAYS}d"
    OFFSITE_NOTE=", copie chiffrée hors serveur OK"
else
    OFFSITE_NOTE=", PAS de copie hors serveur (OFFSITE_REMOTE non défini)"
fi

ping_healthcheck ""
echo "$(date -Iseconds) sauvegarde OK : $DB_FILE $FILES_FILE$OFFSITE_NOTE"
