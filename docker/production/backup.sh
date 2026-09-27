#!/bin/sh
# Sauvegarde quotidienne de la base PICHA Ticket (à lancer via cron sur le VPS) :
#   0 3 * * * /opt/picha-ticket/docker/production/backup.sh >> /var/log/picha-backup.log 2>&1
set -eu

cd "$(dirname "$0")"
. ./.env

BACKUP_DIR=/var/backups/picha-ticket
RETENTION_DAYS=14
mkdir -p "$BACKUP_DIR"

FILE="$BACKUP_DIR/picha-ticket-$(date +%Y%m%d-%H%M%S).sql.gz"
docker compose -f docker-compose.prod.yml exec -T postgres \
    pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" --no-owner | gzip > "$FILE"

find "$BACKUP_DIR" -name 'picha-ticket-*.sql.gz' -mtime +"$RETENTION_DAYS" -delete
echo "$(date -Iseconds) sauvegarde OK : $FILE"
